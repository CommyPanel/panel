<?php

namespace App\GitHub;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * The GitHub App this panel created for itself (GitHub's "manifest flow"), and everything it
 * does with it: read which repositories it may see, and hand out a short-lived read-only
 * token for one repository when a deployment clones it.
 *
 * Only api.github.com and github.com are ever contacted, always over https, with fixed paths.
 * The private key and the webhook secret are stored encrypted and never leave this class
 * (the page gets only names). Anything GitHub answers is treated as untrusted data.
 */
class GitHubApp
{
    private const KEY = 'github.app';

    private const API = 'https://api.github.com';

    public const REPO = '/^[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9._-]{1,100}$/';

    public function connected(): bool
    {
        return is_int($this->read()['app_id'] ?? null);
    }

    public function installed(): bool
    {
        return $this->connected() && ((int) ($this->read()['installation_id'] ?? 0)) > 0;
    }

    /**
     * What a page may know.
     *
     * @return array{connected: bool, installed: bool, name: string|null, slug: string|null, account: string|null, install_url: string|null, settings_url: string|null}
     */
    public function toArray(): array
    {
        $d = $this->read();
        $slug = $this->connected() ? (string) ($d['slug'] ?? '') : null;

        return [
            'connected' => $this->connected(),
            'installed' => $this->installed(),
            'name' => $this->connected() ? (string) ($d['name'] ?? '') : null,
            'slug' => $slug,
            'account' => $this->installed() ? (string) ($d['account'] ?? '') : null,
            'install_url' => $slug === null ? null : "https://github.com/apps/{$slug}/installations/new",
            'settings_url' => $slug === null ? null : "https://github.com/settings/apps/{$slug}",
        ];
    }

    public function webhookSecret(): ?string
    {
        return $this->decrypt('webhook_secret');
    }

    public function installationId(): ?int
    {
        $id = (int) ($this->read()['installation_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * What GitHub needs to create the app: sent from the browser as a form to github.com.
     *
     * @return array<string, mixed>
     */
    public function manifest(string $panelUrl): array
    {
        $panelUrl = rtrim($panelUrl, '/');

        return [
            'name' => 'CommyPanel '.substr(bin2hex(random_bytes(3)), 0, 5),
            'url' => $panelUrl,
            'hook_attributes' => ['url' => $panelUrl.'/webhooks/github', 'active' => true],
            'redirect_url' => $panelUrl.'/settings/github/callback',
            'setup_url' => $panelUrl.'/settings/github/installed',
            'public' => false,
            'default_permissions' => ['contents' => 'read', 'metadata' => 'read'],
            'default_events' => ['push'],
        ];
    }

    /**
     * Trade the one-time code GitHub sent back for the app's credentials, and keep them.
     *
     * @throws GitHubFailed
     */
    public function convert(string $code): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{10,100}$/', $code) !== 1) {
            throw new GitHubFailed('GitHub sent something unexpected.');
        }

        $data = $this->call(fn () => $this->http()->post(self::API."/app-manifests/{$code}/conversions"), 'create the app');

        $id = $data['id'] ?? null;
        $pem = $data['pem'] ?? null;
        $secret = $data['webhook_secret'] ?? null;
        $slug = $data['slug'] ?? null;

        if (! is_int($id) || ! is_string($pem) || ! is_string($secret) || ! is_string($slug) || preg_match('/^[A-Za-z0-9-]{1,100}$/', $slug) !== 1 || openssl_pkey_get_private($pem) === false) {
            throw new GitHubFailed('GitHub answered with something that is not an app.');
        }

        $this->write([
            'app_id' => $id,
            'slug' => $slug,
            'name' => is_string($data['name'] ?? null) ? mb_substr($data['name'], 0, 100) : $slug,
            'pem' => Crypt::encryptString($pem),
            'webhook_secret' => Crypt::encryptString($secret),
            'installation_id' => 0,
            'account' => null,
        ]);
    }

    /**
     * Find where the app was installed. Done after the person installed it, and on demand.
     *
     * @throws GitHubFailed
     */
    public function syncInstallation(): void
    {
        $list = $this->call(fn () => $this->asApp()->get(self::API.'/app/installations', ['per_page' => 10]), 'look up the installation');

        foreach ($list as $installation) {
            $id = $installation['id'] ?? null;
            $login = $installation['account']['login'] ?? null;

            if (is_int($id) && is_string($login) && ! isset($installation['suspended_at'])) {
                $this->write([...$this->read(), 'installation_id' => $id, 'account' => mb_substr($login, 0, 100)]);
                Cache::forget('github.repos');

                return;
            }
        }

        $this->write([...$this->read(), 'installation_id' => 0, 'account' => null]);
        Cache::forget('github.repos');
    }

    /**
     * The repositories the installation may read: full name, default branch, private.
     *
     * @return list<array{name: string, branch: string, private: bool}>
     *
     * @throws GitHubFailed
     */
    public function repositories(): array
    {
        return Cache::remember('github.repos', 60, function () {
            $token = $this->installationToken(null);
            $out = [];

            for ($page = 1; $page <= 5; $page++) {
                $data = $this->call(fn () => $this->http()->withToken($token)->get(self::API.'/installation/repositories', ['per_page' => 100, 'page' => $page]), 'list the repositories');
                $items = is_array($data['repositories'] ?? null) ? $data['repositories'] : [];

                foreach ($items as $repo) {
                    $name = $repo['full_name'] ?? null;

                    if (is_string($name) && preg_match(self::REPO, $name) === 1) {
                        $branch = is_string($repo['default_branch'] ?? null) && preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $repo['default_branch']) === 1 ? $repo['default_branch'] : 'main';
                        $out[] = ['name' => $name, 'branch' => $branch, 'private' => (bool) ($repo['private'] ?? true)];
                    }
                }

                if (count($items) < 100) {
                    break;
                }
            }

            usort($out, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

            return $out;
        });
    }

    /**
     * A token that may only read the code of one repository (or, with null, list them), valid
     * for about an hour. Made for each clone and never stored.
     *
     * @throws GitHubFailed
     */
    public function installationToken(?string $repo): string
    {
        $id = $this->installationId() ?? throw new GitHubFailed('GitHub is not installed on an account yet. Connect it in Settings > GitHub.');

        $body = $repo === null ? [] : ['repositories' => [explode('/', $repo, 2)[1] ?? ''], 'permissions' => ['contents' => 'read']];

        if ($repo !== null && preg_match(self::REPO, $repo) !== 1) {
            throw new GitHubFailed('The repository name is not valid.');
        }

        $data = $this->call(fn () => $this->asApp()->post(self::API."/app/installations/{$id}/access_tokens", $body === [] ? (object) [] : $body), 'get access to the repository');
        $token = $data['token'] ?? null;

        if (! is_string($token) || preg_match('/^[\x21-\x7e]{8,500}$/', $token) !== 1) {
            throw new GitHubFailed('GitHub answered with something that is not a token.');
        }

        return $token;
    }

    public function disconnect(): void
    {
        Setting::query()->whereKey(self::KEY)->delete();
        Cache::forget('github.repos');
    }

    private function http(): PendingRequest
    {
        return Http::withoutRedirecting()
            ->connectTimeout(10)
            ->timeout(20)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'CommyPanel/'.config('commypanel.version', 'dev'), 'X-GitHub-Api-Version' => '2022-11-28']);
    }

    /**
     * Signed in as the app itself (a short-lived JWT), which can only ask about its own installations.
     */
    private function asApp(): PendingRequest
    {
        return $this->http()->withToken($this->jwt());
    }

    /**
     * @throws GitHubFailed
     */
    private function jwt(): string
    {
        $id = $this->read()['app_id'] ?? null;
        $pem = $this->decrypt('pem');

        if (! is_int($id) || $pem === null) {
            throw new GitHubFailed('GitHub is not connected.');
        }

        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $input = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64((string) json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $id]));

        $key = openssl_pkey_get_private($pem);

        if ($key === false || ! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new GitHubFailed('The app\'s key could not be used.');
        }

        return $input.'.'.$b64($signature);
    }

    /**
     * @return array<mixed>
     *
     * @throws GitHubFailed
     */
    private function call(\Closure $request, string $what): array
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new GitHubFailed('GitHub could not be reached.');
        }

        $status = $response->status();

        if ($status === 401 || $status === 403 || $status === 404) {
            throw new GitHubFailed("GitHub refused to {$what} ({$status}). The app may have been uninstalled or deleted on GitHub.");
        }

        if (! $response->successful()) {
            throw new GitHubFailed("GitHub answered {$status} when asked to {$what}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new GitHubFailed('GitHub answered with something that is not JSON.');
        }

        return $json;
    }

    private function decrypt(string $field): ?string
    {
        $value = $this->read()[$field] ?? '';

        if ($value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null; // the APP_KEY changed since it was saved
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $raw = Setting::read(self::KEY);
        $data = $raw === null ? [] : json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data): void
    {
        Setting::write(self::KEY, (string) json_encode($data));
    }
}
