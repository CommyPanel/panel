<?php

namespace App\Dns;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * The domain the panel itself is served on (chosen during first-run setup)
 * and the state of its DNS check and certificate. No app may use the name.
 */
class PanelDomain
{
    public const SLUG = 'panel';

    private const DOMAIN = 'panel.domain';

    private const DNS = 'panel.dns';

    private const CERT_STATUS = 'panel.cert_status';

    private const CERT_EXPIRES = 'panel.cert_expires_at';

    private const CERT_ERROR = 'panel.cert_error';

    private const CERT_ATTEMPT = 'panel.cert_attempt_at';

    private const NEXT = 'panel.next_domain';

    private const NEXT_DNS = 'panel.next_dns';

    private const NEXT_STATUS = 'panel.next_status';

    private const NEXT_ERROR = 'panel.next_error';

    public function name(): ?string
    {
        return Setting::read(self::DOMAIN) ?: null;
    }

    public function isTaken(string $name): bool
    {
        return $this->name() === $name;
    }

    /**
     * Set (or clear) the domain; DNS and certificate state start over.
     */
    public function set(?string $name): void
    {
        Setting::write(self::DOMAIN, $name === '' ? null : $name);
        Setting::write(self::DNS, null);
        $this->clearCertificate();
    }

    /**
     * @param  array<string, mixed>  $check  a DomainCheck, as an array
     */
    public function recordDns(string $status, array $check): void
    {
        Setting::write(self::DNS, (string) json_encode(['status' => $status, 'checked_at' => now()->toIso8601String(), ...$check]));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dns(): ?array
    {
        $raw = Setting::read(self::DNS);
        $decoded = $raw === null ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function dnsVerified(): bool
    {
        return ($this->dns()['status'] ?? null) === 'verified';
    }

    /** none | issuing | active | failed */
    public function certificateStatus(): string
    {
        return Setting::read(self::CERT_STATUS) ?: 'none';
    }

    public function certificateExpiresAt(): ?Carbon
    {
        $value = Setting::read(self::CERT_EXPIRES);

        return $value === null ? null : Carbon::parse($value);
    }

    public function certificateError(): ?string
    {
        return Setting::read(self::CERT_ERROR) ?: null;
    }

    public function lastAttemptAt(): ?Carbon
    {
        $value = Setting::read(self::CERT_ATTEMPT);

        return $value === null ? null : Carbon::parse($value);
    }

    public function certificateUsable(): bool
    {
        $expires = $this->certificateExpiresAt();

        return $this->certificateStatus() === 'active' && $expires !== null && $expires->isFuture();
    }

    public function markIssuing(): void
    {
        Setting::write(self::CERT_STATUS, 'issuing');
        Setting::write(self::CERT_ATTEMPT, now()->toIso8601String());
    }

    public function markIssued(Carbon $expiresAt): void
    {
        Setting::write(self::CERT_STATUS, 'active');
        Setting::write(self::CERT_EXPIRES, $expiresAt->toIso8601String());
        Setting::write(self::CERT_ERROR, null);
    }

    /**
     * @param  bool  $keepServing  a still-valid certificate stays in use while renewal is retried
     */
    public function markFailed(string $error, bool $keepServing): void
    {
        Setting::write(self::CERT_STATUS, $keepServing ? 'active' : 'failed');
        Setting::write(self::CERT_ERROR, $error);
    }

    private function clearCertificate(): void
    {
        foreach ([self::CERT_STATUS, self::CERT_EXPIRES, self::CERT_ERROR, self::CERT_ATTEMPT] as $key) {
            Setting::write($key, null);
        }
    }

    /**
     * A domain the panel is being moved to. The current domain keeps serving,
     * untouched, until the new one has verified DNS and a certificate.
     */
    public function next(): ?string
    {
        return Setting::read(self::NEXT) ?: null;
    }

    public function setNext(?string $name): void
    {
        Setting::write(self::NEXT, $name === '' ? null : $name);
        Setting::write(self::NEXT_DNS, null);
        Setting::write(self::NEXT_STATUS, null);
        Setting::write(self::NEXT_ERROR, null);
    }

    /**
     * @param  array<string, mixed>  $check
     */
    public function recordNextDns(string $status, array $check): void
    {
        Setting::write(self::NEXT_DNS, (string) json_encode(['status' => $status, 'checked_at' => now()->toIso8601String(), ...$check]));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function nextDns(): ?array
    {
        $raw = Setting::read(self::NEXT_DNS);
        $decoded = $raw === null ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function nextDnsVerified(): bool
    {
        return ($this->nextDns()['status'] ?? null) === 'verified';
    }

    /** none | issuing | failed */
    public function nextStatus(): string
    {
        return Setting::read(self::NEXT_STATUS) ?: 'none';
    }

    public function nextError(): ?string
    {
        return Setting::read(self::NEXT_ERROR) ?: null;
    }

    public function markNextIssuing(): void
    {
        Setting::write(self::NEXT_STATUS, 'issuing');
        Setting::write(self::NEXT_ERROR, null);
    }

    public function markNextFailed(string $error): void
    {
        Setting::write(self::NEXT_STATUS, 'failed');
        Setting::write(self::NEXT_ERROR, $error);
    }

    /**
     * The new domain has its certificate: it becomes the panel's domain.
     */
    public function promoteNext(Carbon $expiresAt): void
    {
        $name = $this->next();

        if ($name === null) {
            return;
        }

        Setting::write(self::DOMAIN, $name);
        Setting::write(self::DNS, Setting::read(self::NEXT_DNS));
        $this->clearCertificate();
        $this->markIssued($expiresAt);
        $this->setNext(null);
    }
}
