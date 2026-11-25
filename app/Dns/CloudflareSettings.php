<?php

namespace App\Dns;

use App\Host\Data\DnsValidation;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * The Cloudflare API token that lets the host agent prove control of wildcard names (it makes and
 * removes the _acme-challenge TXT record). The person makes the token themselves, limited to editing
 * DNS in the zones they choose. It is stored encrypted, never sent to a page, and handed to the agent
 * only for the request that needs it; the agent keeps nothing. Without a token a wildcard certificate
 * still works, with a TXT record the person creates by hand (see IssueCertificate).
 */
class CloudflareSettings
{
    private const TOKEN = 'dns.cloudflare_token';

    private const SAVED_AT = 'dns.cloudflare_saved_at';

    private const ZONE = 'dns.cloudflare_zone';

    public function configured(): bool
    {
        return $this->token() !== null;
    }

    public function token(): ?string
    {
        $stored = Setting::read(self::TOKEN);

        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // The panel's key changed: the token cannot be read any more.
            return null;
        }
    }

    public function validation(): ?DnsValidation
    {
        $token = $this->token();

        return $token === null ? null : new DnsValidation('cloudflare', $token);
    }

    public function savedAt(): ?Carbon
    {
        $at = Setting::read(self::SAVED_AT);

        return $at === null ? null : Carbon::parse($at);
    }

    /** The zone the token was last tested against, for showing. */
    public function zone(): ?string
    {
        return Setting::read(self::ZONE) ?: null;
    }

    public function save(#[\SensitiveParameter] string $token, string $zone): void
    {
        Setting::write(self::TOKEN, Crypt::encryptString($token));
        Setting::write(self::SAVED_AT, now()->toIso8601String());
        Setting::write(self::ZONE, $zone);
    }

    public function forget(): void
    {
        Setting::write(self::TOKEN, null);
        Setting::write(self::SAVED_AT, null);
        Setting::write(self::ZONE, null);
    }

    /** What a token looks like: letters, digits, dashes and underscores, as Cloudflare makes them. */
    public static function looksValid(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{20,80}$/', $token) === 1;
    }
}
