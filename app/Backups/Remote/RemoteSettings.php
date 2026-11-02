<?php

namespace App\Backups\Remote;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;

/**
 * Where backups are copied to, kept in the panel's settings. The secret key is
 * encrypted and is never handed to a page, only whether one is set.
 */
class RemoteSettings
{
    private const KEY = 'backups.s3';

    /**
     * @return array{enabled: bool, endpoint: string, region: string, bucket: string, prefix: string, access_key: string, keep: int, last_ok_at: string|null, last_error: string|null, has_secret: bool}
     */
    public function toArray(): array
    {
        $d = $this->read();

        return [
            'enabled' => (bool) ($d['enabled'] ?? false),
            'endpoint' => (string) ($d['endpoint'] ?? ''),
            'region' => (string) ($d['region'] ?? ''),
            'bucket' => (string) ($d['bucket'] ?? ''),
            'prefix' => (string) ($d['prefix'] ?? ''),
            'access_key' => (string) ($d['access_key'] ?? ''),
            'keep' => (int) ($d['keep'] ?? 14),
            'last_ok_at' => $d['last_ok_at'] ?? null,
            'last_error' => $d['last_error'] ?? null,
            'has_secret' => ($d['secret'] ?? '') !== '',
        ];
    }

    public function enabled(): bool
    {
        return $this->toArray()['enabled'] && $this->client() !== null;
    }

    public function client(): ?S3Client
    {
        $d = $this->read();
        $secret = $this->secret($d);

        if ($secret === null || ($d['endpoint'] ?? '') === '' || ($d['bucket'] ?? '') === '' || ($d['access_key'] ?? '') === '') {
            return null;
        }

        return new S3Client($d['endpoint'], ($d['region'] ?? '') ?: 'us-east-1', $d['bucket'], $d['access_key'], $secret);
    }

    /**
     * A client for settings that are not saved yet, for the connection test. An
     * empty secret means the saved one.
     *
     * @param  array<string, mixed>  $input
     */
    public function clientFor(array $input): S3Client
    {
        $secret = (string) ($input['secret_key'] ?? '');

        if ($secret === '') {
            $secret = $this->secret($this->read()) ?? '';
        }

        return new S3Client((string) $input['endpoint'], ((string) ($input['region'] ?? '')) ?: 'us-east-1', (string) $input['bucket'], (string) $input['access_key'], $secret);
    }

    /**
     * @param  array<string, mixed>  $input  an empty `secret_key` keeps the saved one
     */
    public function save(array $input): void
    {
        $old = $this->read();
        $secret = (string) ($input['secret_key'] ?? '');

        $this->write([
            'enabled' => (bool) ($input['enabled'] ?? false),
            'endpoint' => rtrim((string) $input['endpoint'], '/'),
            'region' => (string) ($input['region'] ?? ''),
            'bucket' => (string) $input['bucket'],
            'prefix' => trim((string) ($input['prefix'] ?? ''), '/'),
            'access_key' => (string) $input['access_key'],
            'keep' => (int) ($input['keep'] ?? 14),
            'secret' => $secret !== '' ? Crypt::encryptString($secret) : ($old['secret'] ?? ''),
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

    public function prefix(): string
    {
        return (string) ($this->read()['prefix'] ?? '');
    }

    public function keep(): int
    {
        return max(1, (int) ($this->read()['keep'] ?? 14));
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function secret(array $data): ?string
    {
        if (($data['secret'] ?? '') === '') {
            return null;
        }

        try {
            return Crypt::decryptString($data['secret']);
        } catch (\Throwable) {
            return null; // the APP_KEY changed since it was saved
        }
    }
}
