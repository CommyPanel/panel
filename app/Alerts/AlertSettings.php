<?php

namespace App\Alerts;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;

/**
 * Where deployment results are sent, kept in the panel's settings. The address is
 * encrypted and never handed to a page (it usually holds the secret of the chat
 * channel), only its host name.
 */
class AlertSettings
{
    public const FORMATS = ['slack', 'discord', 'json'];

    public const EVENTS = ['failures', 'all'];

    private const KEY = 'alerts.webhook';

    /**
     * @return array{enabled: bool, format: string, events: string, host: string, has_url: bool, last_ok_at: string|null, last_error: string|null}
     */
    public function toArray(): array
    {
        $d = $this->read();
        $url = $this->url();

        return [
            'enabled' => (bool) ($d['enabled'] ?? false),
            'format' => in_array($d['format'] ?? '', self::FORMATS, true) ? $d['format'] : 'slack',
            'events' => in_array($d['events'] ?? '', self::EVENTS, true) ? $d['events'] : 'failures',
            'host' => $url === null ? '' : (string) parse_url($url, PHP_URL_HOST),
            'has_url' => $url !== null,
            'last_ok_at' => $d['last_ok_at'] ?? null,
            'last_error' => $d['last_error'] ?? null,
        ];
    }

    public function enabled(): bool
    {
        return $this->toArray()['enabled'] && $this->url() !== null;
    }

    /**
     * Whether a finished deployment is worth a message under the chosen setting.
     */
    public function wants(bool $succeeded): bool
    {
        return $this->enabled() && ($this->toArray()['events'] === 'all' || ! $succeeded);
    }

    public function url(): ?string
    {
        $encrypted = $this->read()['url'] ?? '';

        if ($encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null; // the APP_KEY changed since it was saved
        }
    }

    /**
     * @param  array<string, mixed>  $input  an empty `url` keeps the saved one
     */
    public function save(array $input): void
    {
        $old = $this->read();
        $url = (string) ($input['url'] ?? '');

        $this->write([
            'enabled' => (bool) ($input['enabled'] ?? false),
            'format' => (string) $input['format'],
            'events' => (string) $input['events'],
            'url' => $url !== '' ? Crypt::encryptString($url) : ($old['url'] ?? ''),
            'last_ok_at' => $old['last_ok_at'] ?? null,
            'last_error' => $old['last_error'] ?? null,
        ]);
    }

    public function clear(): void
    {
        Setting::query()->whereKey(self::KEY)->delete();
    }

    public function recordSuccess(): void
    {
        $this->write([...$this->read(), 'last_ok_at' => Date::now()->toIso8601String(), 'last_error' => null]);
    }

    public function recordFailure(string $message): void
    {
        $this->write([...$this->read(), 'last_error' => $message]);
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
