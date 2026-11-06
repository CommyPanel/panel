<?php

namespace App\Alerts;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sends the result of a deployment to the configured chat or webhook. The message holds
 * the app, the deployment, the commit and the reason of a failure, with a link to the
 * panel: never the log, environment variables or any secret. Text that came from a repo
 * (the commit message) is made harmless for the chat (no mentions, no links).
 */
class DeployAlerts
{
    public function __construct(
        private readonly AlertSettings $settings,
        private readonly WebhookGuard $guard,
    ) {}

    /**
     * @throws AlertFailed
     */
    public function deployment(Deployment $deployment): void
    {
        $deployment->loadMissing('app');
        $app = $deployment->app;
        $ok = in_array($deployment->status->value, ['active', 'superseded'], true);

        $rolledBack = $deployment->status === DeploymentStatus::RolledBack;
        $title = match (true) {
            $ok => "Deployed {$app->name} (#{$deployment->number})",
            $rolledBack => "{$app->name} (#{$deployment->number}) was rolled back automatically",
            default => "Deployment of {$app->name} (#{$deployment->number}) failed",
        };

        $lines = [];

        if ($deployment->commit_sha !== null) {
            $lines[] = 'Commit '.substr($deployment->commit_sha, 0, 12).($deployment->commit_message === null ? '' : ': '.Str::limit(strtok($deployment->commit_message, "\n") ?: '', 120));
        }

        if (! $ok && $deployment->error !== null) {
            $lines[] = Str::limit($deployment->error, 300);
        }

        $link = route('apps.show', ['app' => $app->slug]);

        $this->post($title, $lines, $link, [
            'event' => match (true) {
                $ok => 'deployment.succeeded',
                $rolledBack => 'deployment.rolled_back',
                default => 'deployment.failed',
            },
            'app' => $app->slug,
            'deployment' => $deployment->number,
            'succeeded' => $ok,
            'commit' => $deployment->commit_sha === null ? null : substr($deployment->commit_sha, 0, 12),
            'error' => $ok ? null : Str::limit((string) $deployment->error, 300),
            'url' => $link,
        ]);
    }

    /**
     * @throws AlertFailed
     */
    public function test(?string $url = null, ?string $format = null): void
    {
        $link = url('/');

        $this->post('CommyPanel test message', ['If you can read this, deployment results will arrive here.'], $link, [
            'event' => 'test',
            'url' => $link,
        ], $url, $format);
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $data  what the generic JSON format sends
     *
     * @throws AlertFailed
     */
    private function post(string $title, array $lines, string $link, array $data, ?string $url = null, ?string $format = null): void
    {
        $url ??= $this->settings->url() ?? throw new AlertFailed('No address is saved.');
        $format ??= $this->settings->toArray()['format'];

        $text = $this->plain($title)."\n".implode("\n", array_map($this->plain(...), $lines))."\n".$link;

        $body = match ($format) {
            'discord' => ['content' => Str::limit($text, 1900, ''), 'allowed_mentions' => ['parse' => []]],
            'json' => ['text' => $text, ...$data],
            default => ['text' => $text],
        };

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(10)
                ->timeout(15)
                ->withOptions(['curl' => [CURLOPT_RESOLVE => [$this->guard->pin($url)]]])
                ->withHeaders(['User-Agent' => 'CommyPanel/'.config('commypanel.version', 'dev')])
                ->asJson()
                ->post($url, $body);
        } catch (ConnectionException) {
            throw new AlertFailed('The address could not be reached.');
        }

        if (! $response->successful()) {
            throw new AlertFailed("The service answered {$response->status()}.");
        }
    }

    /**
     * Makes text that came from a repo or a build harmless in a chat: no links or
     * mentions (Slack's <!channel>, <@user>, Discord's @everyone), no control characters.
     */
    private function plain(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text);
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        return str_replace('@', "@\u{200B}", $text);
    }
}
