<?php

namespace App\Tls;

use App\Dns\CloudflareSettings;
use App\Dns\DnsResolver;
use App\Dns\DomainName;
use App\Enums\CertificateStatus;
use App\Enums\DomainStatus;
use App\Jobs\IssueAppCertificate;
use App\Models\App;
use App\Models\Certificate;
use Illuminate\Support\Carbon;

/**
 * Decides whether an app needs a (new) certificate and queues one. Called
 * whenever something that affects the answer changes, and daily for renewals.
 */
class TlsPlanner
{
    public function __construct(
        private readonly HttpsSettings $https,
        private readonly DnsResolver $dns,
        private readonly CloudflareSettings $cloudflare,
    ) {}

    /**
     * Whether a name needs the DNS check (a wildcard), which HTTP cannot do.
     *
     * @param  list<string>  $domains
     * @return list<string>
     */
    public function wildcards(array $domains): array
    {
        return array_values(array_filter($domains, fn (string $d) => DomainName::isWildcard($d)));
    }

    /**
     * Whether the agent can make the TXT record itself (a Cloudflare token) or the person has to.
     */
    public function automaticDns(): bool
    {
        return $this->cloudflare->configured();
    }

    /**
     * Whether every TXT record a waiting order asked for can be seen in DNS now.
     *
     * @param  array{domains: list<string>, records: list<array{name: string, value: string}>, expires_at: string|null}  $pending
     */
    public function recordsVisible(array $pending): bool
    {
        foreach ($pending['records'] as $record) {
            if (! in_array($record['value'], $this->dns->txt($record['name']), true)) {
                return false;
            }
        }

        return $pending['records'] !== [];
    }

    /**
     * The waiting order of a certificate, unless it ran out.
     *
     * @return array{domains: list<string>, records: list<array{name: string, value: string}>, expires_at: string|null}|null
     */
    public function pending(?Certificate $cert): ?array
    {
        $pending = $cert?->dns_pending;

        if ($pending === null || ($pending['expires_at'] ?? null) !== null && Carbon::parse($pending['expires_at'])->isPast()) {
            return null;
        }

        return $pending;
    }

    /**
     * Domains that point at this server, and so can pass validation.
     *
     * @return list<string>
     */
    public function eligibleDomains(App $app): array
    {
        // A wildcard is proven through DNS, not by visiting it, so it may sit behind Cloudflare's proxy.
        return array_values(array_map(
            fn (mixed $name): string => (string) $name,
            $app->domains()
                ->where(fn ($q) => $q->where('status', DomainStatus::Verified)->orWhere(fn ($q) => $q->where('status', DomainStatus::Proxied)->where('name', 'like', '*.%')))
                ->orderBy('name')->pluck('name')->all(),
        ));
    }

    public function ensure(App $app): bool
    {
        if (! $this->https->enabled() || ! $this->needsIssue($app)) {
            return false;
        }

        IssueAppCertificate::dispatch($app->id);

        return true;
    }

    public function needsIssue(App $app): bool
    {
        $domains = $this->eligibleDomains($app);

        if ($domains === []) {
            return false;
        }

        $cert = $app->certificate()->first();

        if ($cert === null) {
            return true;
        }

        // Wildcard names without a Cloudflare token: an order waits for the person's TXT records. Nothing
        // happens until they can be seen; then the order is finished. A different set of names starts over.
        $pending = $this->wildcards($domains) !== [] && ! $this->automaticDns() ? $this->pending($cert) : null;

        if ($pending !== null && array_diff($domains, $pending['domains']) === [] && array_diff($pending['domains'], $domains) === []) {
            return $this->recordsVisible($pending);
        }

        // An attempt is already running (or the worker died a while ago).
        if ($cert->status === CertificateStatus::Issuing && $cert->last_attempt_at?->isAfter(now()->subMinutes(15))) {
            return false;
        }

        if ($cert->status === CertificateStatus::Failed && $cert->last_attempt_at?->isAfter(now()->subHours((int) config('commypanel.https.retry_after_hours')))) {
            return false;
        }

        // A domain that is not on the certificate yet.
        if (array_diff($domains, $cert->domains ?? []) !== []) {
            return true;
        }

        if ($cert->status === CertificateStatus::Failed) {
            return true;
        }

        return $cert->expires_at === null
            || $cert->expires_at->isBefore(now()->addDays((int) config('commypanel.https.renew_before_days')));
    }
}
