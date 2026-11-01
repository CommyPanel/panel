<?php

namespace App\Updates;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use Throwable;

/**
 * Finds out whether a newer CommyPanel release exists by asking GitHub for the
 * repository's releases. This is the one call the panel makes to a third party
 * on its own: it happens in the background when someone signs in, at most once
 * every few hours, sends nothing but the usual request details and the running
 * version, and can be switched off (in the UI, or with COMMYPANEL_UPDATE_CHECK).
 *
 * Whatever GitHub answers is untrusted: tags and addresses are validated, text
 * is plain, and release notes are rendered from Markdown without raw HTML,
 * unsafe links or remote images.
 */
class UpdateChecker
{
    private const CACHE_KEY = 'commypanel.updates';

    private const SETTING = 'updates.check';

    /** Stable tags only; pre-releases (v0.2.0-rc.1) are never offered. */
    private const TAG = '/^v(\d+\.\d+\.\d+)$/';

    private const VERSION = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';

    private const REPOSITORY = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/';

    /** How many releases are kept, newest first. A server further behind sees the newest ones. */
    private const KEEP = 6;

    private const NOTES_LIMIT = 30000;

    public function current(): string
    {
        return (string) config('commypanel.version');
    }

    /**
     * A checkout has no version to compare, so there is nothing to check.
     */
    public function supported(): bool
    {
        return preg_match(self::VERSION, $this->current()) === 1;
    }

    public function forcedOff(): bool
    {
        return ! (bool) config('commypanel.updates.check');
    }

    /**
     * Whether the automatic check (after sign-in) is on.
     */
    public function enabled(): bool
    {
        return ! $this->forcedOff() && Setting::read(self::SETTING) !== '0';
    }

    public function setEnabled(bool $enabled): void
    {
        Setting::write(self::SETTING, $enabled ? '1' : '0');
    }

    /**
     * Whether an automatic check should be made now: switched on, a real
     * version, and neither a recent answer nor a recent failure on record.
     */
    public function shouldCheck(): bool
    {
        if (! $this->enabled() || ! $this->supported()) {
            return false;
        }

        /** @var array<string, mixed>|null $data */
        $data = Cache::get(self::CACHE_KEY);

        if ($data === null) {
            return true;
        }

        $now = now()->getTimestamp();

        if (isset($data['failed_at']) && $now - (int) $data['failed_at'] < (int) config('commypanel.updates.retry_minutes') * 60) {
            return false;
        }

        return $now - (int) ($data['checked_at'] ?? 0) >= (int) config('commypanel.updates.ttl_hours') * 3600;
    }

    /**
     * Ask GitHub now and remember the answer. A failure keeps the previous
     * answer and records why. Does nothing when checks are forbidden by the
     * server configuration or this is a development checkout.
     */
    public function check(): UpdateStatus
    {
        if ($this->forcedOff() || ! $this->supported()) {
            return $this->status();
        }

        /** @var array<string, mixed> $previous */
        $previous = Cache::get(self::CACHE_KEY) ?? [];

        try {
            $releases = $this->fetch();
            Cache::forever(self::CACHE_KEY, ['checked_at' => now()->getTimestamp(), 'releases' => $releases, 'error' => null, 'failed_at' => null]);
        } catch (UpdateCheckFailed $e) {
            Cache::forever(self::CACHE_KEY, [...$previous, 'error' => $e->getMessage(), 'failed_at' => now()->getTimestamp()]);
        }

        return $this->status();
    }

    /**
     * What is known from the last answer. Never contacts GitHub.
     */
    public function status(): UpdateStatus
    {
        /** @var array<string, mixed> $data */
        $data = Cache::get(self::CACHE_KEY) ?? [];
        /** @var list<array{version: string, name: string, url: string, published_at: string|null, notes_html: string}> $releases */
        $releases = $data['releases'] ?? [];
        $current = $this->current();
        $supported = $this->supported();

        $newer = [];
        $installed = null;

        foreach ($releases as $release) {
            if ($supported && version_compare($release['version'], $current, '>')) {
                $newer[] = $release;
            } elseif ($release['version'] === $current) {
                $installed = $release;
            }
        }

        $latest = $releases[0]['version'] ?? null;

        return new UpdateStatus(
            current: $current,
            supported: $supported,
            enabled: $this->enabled(),
            forcedOff: $this->forcedOff(),
            latest: $latest,
            available: $supported && $latest !== null && version_compare($latest, $current, '>'),
            newer: $newer,
            installed: $installed,
            checkedAt: isset($data['checked_at']) ? CarbonImmutable::createFromTimestamp((int) $data['checked_at']) : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
            releasesUrl: 'https://github.com/'.config('commypanel.updates.repository').'/releases',
        );
    }

    /**
     * @return list<array{version: string, name: string, url: string, published_at: string|null, notes_html: string}>
     *
     * @throws UpdateCheckFailed
     */
    private function fetch(): array
    {
        $repository = $this->repository();

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'CommyPanel/'.$this->current(),
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->timeout((int) config('commypanel.updates.timeout'))
                ->get("https://api.github.com/repos/{$repository}/releases", ['per_page' => 30]);
        } catch (ConnectionException) {
            throw new UpdateCheckFailed('GitHub could not be reached from this server.');
        }

        if ($response->status() === 403 || $response->status() === 429) {
            throw new UpdateCheckFailed('GitHub is limiting requests from this server right now. It will try again later.');
        }

        if (! $response->successful()) {
            throw new UpdateCheckFailed("GitHub answered with an error (HTTP {$response->status()}).");
        }

        try {
            $list = $response->json();
        } catch (Throwable) {
            $list = null;
        }

        if (! is_array($list)) {
            throw new UpdateCheckFailed('GitHub sent an answer that could not be read.');
        }

        $releases = [];

        foreach ($list as $item) {
            if (! is_array($item) || ($item['draft'] ?? false) || ($item['prerelease'] ?? false)) {
                continue;
            }

            $tag = $item['tag_name'] ?? null;

            if (! is_string($tag) || preg_match(self::TAG, $tag, $match) !== 1) {
                continue;
            }

            $published = isset($item['published_at']) && is_string($item['published_at']) ? strtotime($item['published_at']) : false;

            $releases[] = [
                'version' => $match[1],
                'name' => Str::limit(trim(strip_tags(is_string($item['name'] ?? null) && $item['name'] !== '' ? $item['name'] : $tag)), 120, ''),
                // Built here, never taken from the answer.
                'url' => "https://github.com/{$repository}/releases/tag/{$tag}",
                'published_at' => $published === false ? null : date('c', $published),
                'notes_html' => $this->render(is_string($item['body'] ?? null) ? $item['body'] : ''),
            ];
        }

        usort($releases, fn (array $a, array $b) => version_compare($b['version'], $a['version']));

        return array_slice($releases, 0, self::KEEP);
    }

    /**
     * Release notes as safe HTML: no raw HTML, no javascript: links, external
     * links open safely, and no remote images (they would make the visitor's
     * browser contact third parties).
     */
    private function render(string $markdown): string
    {
        $html = Str::markdown(Str::limit($markdown, self::NOTES_LIMIT, ''), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'external_link' => [
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'html_class' => '',
                'nofollow' => '',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ], [new ExternalLinkExtension]); // Str::markdown already speaks GitHub-flavoured Markdown

        return (string) preg_replace('/<img\b[^>]*>/i', '', $html);
    }

    private function repository(): string
    {
        $repository = (string) config('commypanel.updates.repository');

        if (preg_match(self::REPOSITORY, $repository) !== 1) {
            throw new UpdateCheckFailed('The update repository is not configured correctly.');
        }

        return $repository;
    }
}
