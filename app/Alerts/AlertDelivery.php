<?php

namespace App\Alerts;

use App\Models\AlertTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shapes a message for one kind of target and posts it. Every kind goes through the same
 * guard (https only, never this server, a reserved range or the metadata range; the address
 * checked is the one connected to; no redirects). The message holds the app, what happened
 * and a link to the panel, never a log, an environment variable or a secret, and text that
 * came from a repo or the outside is made harmless: no mentions, no links.
 */
class AlertDelivery
{
    public const TOKEN = '/^\d{6,12}:[A-Za-z0-9_-]{30,50}$/';

    public const CHAT_ID = '/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/';

    public function __construct(private readonly WebhookGuard $guard) {}

    /**
     * @throws AlertFailed
     */
    public function deliver(AlertTarget $target, AlertMessage $message): void
    {
        $secret = $target->secret() ?? throw new AlertFailed('The saved address cannot be read. Enter it again.');
        $kind = $target->kind;
        $problem = $message->event?->severity() === AlertEvent::PROBLEM;

        if ($kind === AlertKind::Telegram) {
            if (preg_match(self::TOKEN, $secret) !== 1 || preg_match(self::CHAT_ID, (string) $target->chat_id) !== 1) {
                throw new AlertFailed('The bot token or the chat id is not valid.');
            }

            $url = 'https://'.AlertKind::TELEGRAM_HOST."/bot{$secret}/sendMessage";
        } else {
            $url = $secret;
        }

        $request = $this->request($url);

        try {
            $response = match ($kind) {
                AlertKind::Slack => $request->asJson()->post($url, ['text' => Str::limit($this->text($message, true), 3500, '')]),
                AlertKind::Discord => $request->asJson()->post($url, ['content' => Str::limit($this->text($message, true), 1900, ''), 'allowed_mentions' => ['parse' => []]]),
                AlertKind::Teams => $request->asJson()->post($url, $this->card($message, $problem)),
                AlertKind::Telegram => $request->asJson()->post($url, ['chat_id' => (string) $target->chat_id, 'text' => Str::limit($this->text($message, false), 3800, ''), 'disable_web_page_preview' => true]),
                AlertKind::Ntfy => $request
                    ->withHeaders($this->ntfyHeaders($message, $problem))
                    ->withBody($this->ntfyBody($message), 'text/plain; charset=utf-8')
                    ->post($url),
                AlertKind::Json => $request->asJson()->post($url, [
                    'text' => $this->text($message, false),
                    'event' => $message->eventKey(),
                    'app' => $message->app,
                    ...$message->data,
                    'url' => $message->link,
                ]),
            };
        } catch (ConnectionException) {
            throw new AlertFailed('The address could not be reached.');
        }

        if (! $response->successful()) {
            throw new AlertFailed("The service answered {$response->status()}.");
        }
    }

    /**
     * @throws AlertFailed
     */
    private function request(string $url): PendingRequest
    {
        return Http::withoutRedirecting()
            ->connectTimeout(10)
            ->timeout(15)
            ->withOptions(['curl' => [CURLOPT_RESOLVE => [$this->guard->pin($url)]]])
            ->withHeaders(['User-Agent' => 'CommyPanel/'.config('commypanel.version', 'dev')]);
    }

    private function text(AlertMessage $m, bool $entities): string
    {
        return self::plain($m->title, $entities)."\n"
            .implode("\n", array_map(fn (string $l): string => self::plain($l, $entities), $m->lines))."\n"
            .$m->link;
    }

    /**
     * An Adaptive Card in the envelope Teams Workflows webhooks take.
     *
     * @return array<string, mixed>
     */
    private function card(AlertMessage $m, bool $problem): array
    {
        $body = [[
            'type' => 'TextBlock',
            'text' => self::plain($m->title, false),
            'weight' => 'Bolder',
            'size' => 'Medium',
            'wrap' => true,
            'color' => $problem ? 'Attention' : ($m->event === null ? 'Default' : 'Good'),
        ]];

        foreach ($m->lines as $line) {
            $body[] = ['type' => 'TextBlock', 'text' => self::plain($line, false), 'wrap' => true, 'isSubtle' => true];
        }

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => $body,
                    'actions' => [['type' => 'Action.OpenUrl', 'title' => 'Open in CommyPanel', 'url' => $m->link]],
                ],
            ]],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function ntfyHeaders(AlertMessage $m, bool $problem): array
    {
        // Headers are ASCII only, on one line.
        $title = Str::limit(trim((string) preg_replace('/\s+/', ' ', Str::ascii(self::plain($m->title, false)))), 100, '');

        return [
            'Title' => $title === '' ? 'CommyPanel' : $title,
            'Priority' => $problem ? 'high' : 'default',
            'Tags' => $problem ? 'warning' : ($m->event === null ? 'bell' : 'white_check_mark'),
            'Click' => $m->link,
        ];
    }

    private function ntfyBody(AlertMessage $m): string
    {
        $lines = array_map(fn (string $l): string => self::plain($l, false), $m->lines);

        return Str::limit(implode("\n", $lines) ?: self::plain($m->title, false), 3500, '');
    }

    /**
     * Makes text that came from a repo or a build harmless in a chat: no links or mentions
     * (Slack's <!channel>, <@user>, @everyone), no control characters. Chats that read markup
     * get it escaped; plain-text targets just lose the angle brackets.
     */
    public static function plain(string $text, bool $entities): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text);
        $text = $entities
            ? str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text)
            : str_replace(['<', '>'], ["\u{2039}", "\u{203A}"], $text);
        // A scheme with a zero-width space in it is not turned into a link.
        $text = (string) preg_replace('~://~', ":\u{200B}//", $text);

        return str_replace('@', "@\u{200B}", $text);
    }
}
