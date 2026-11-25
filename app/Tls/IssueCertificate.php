<?php

namespace App\Tls;

use App\Alerts\CertificateAlerts;
use App\Audit\AuditLogger;
use App\Deploy\Traffic\AppRouting;
use App\Dns\CloudflareSettings;
use App\Enums\CertificateStatus;
use App\Host\Data\CertificateInfo;
use App\Host\Data\CertificateRequest;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Certificate;
use Illuminate\Support\Facades\Log;

/**
 * Gets (or renews) an app's certificate. Plain names are proven over HTTP. A wildcard name is proven
 * through DNS: with a Cloudflare token the agent makes the TXT record itself; without one the order
 * waits for the TXT records the person creates, and is finished once they can be seen (and a renewal
 * asks for new ones, because Let's Encrypt gives new values every time).
 */
class IssueCertificate
{
    public function __construct(
        private readonly HostDriver $host,
        private readonly HttpsSettings $https,
        private readonly TlsPlanner $planner,
        private readonly AppRouting $routing,
        private readonly AuditLogger $audit,
        private readonly CertificateAlerts $alerts,
        private readonly CloudflareSettings $cloudflare,
    ) {}

    public function __invoke(App $app): void
    {
        $domains = $this->planner->eligibleDomains($app);

        if (! $this->https->enabled() || $domains === []) {
            return;
        }

        $cert = Certificate::query()->firstOrNew(['app_id' => $app->id]);
        $hadUsable = $cert->exists && $cert->isUsable();

        if ($this->planner->wildcards($domains) !== [] && ! $this->planner->automaticDns()) {
            $this->withTxtRecords($app, $cert, $domains, $hadUsable);

            return;
        }

        $cert->fill(['status' => CertificateStatus::Issuing, 'last_attempt_at' => now()])->save();

        try {
            $info = $this->host->issueCertificate(new CertificateRequest(
                $app->slug,
                $domains,
                $this->https->email(),
                $this->https->staging(),
                $this->planner->wildcards($domains) === [] ? null : $this->cloudflare->validation(),
            ));
        } catch (HostOperationFailed $e) {
            $this->failed($app, $cert, $hadUsable, $e->getMessage());

            return;
        }

        $this->issued($app, $cert, $info);
    }

    /**
     * Wildcard names and no Cloudflare token: ask for the TXT records, or finish the order once they are there.
     *
     * @param  list<string>  $domains
     */
    private function withTxtRecords(App $app, Certificate $cert, array $domains, bool $hadUsable): void
    {
        $pending = $this->planner->pending($cert);
        $same = $pending !== null && array_diff($domains, $pending['domains']) === [] && array_diff($pending['domains'], $domains) === [];

        if ($same) {
            // Waiting for the person: nothing to do until the records can be seen.
            if (! $this->planner->recordsVisible($pending)) {
                return;
            }

            $cert->fill(['status' => CertificateStatus::Issuing, 'last_attempt_at' => now()])->save();

            try {
                $info = $this->host->completeDnsCertificate($app->slug);
            } catch (HostOperationFailed $e) {
                // The order is spent either way; the next attempt asks for new records.
                $cert->forceFill(['dns_pending' => null])->save();
                $this->failed($app, $cert, $hadUsable, $e->getMessage());

                return;
            }

            $this->issued($app, $cert, $info);

            return;
        }

        $cert->fill(['last_attempt_at' => now()]);

        try {
            $challenge = $this->host->prepareDnsCertificate(new CertificateRequest($app->slug, $domains, $this->https->email(), $this->https->staging()));
        } catch (HostOperationFailed $e) {
            $this->failed($app, $cert, $hadUsable, $e->getMessage());

            return;
        }

        $cert->fill([
            // A certificate that is still valid keeps serving while a renewal waits for its records.
            'status' => $hadUsable ? CertificateStatus::Active : CertificateStatus::AwaitingDns,
            'dns_pending' => [
                'domains' => $domains,
                'records' => $challenge->records,
                'expires_at' => $challenge->expiresAt?->format(DATE_ATOM),
            ],
            'last_error' => null,
        ])->save();

        $this->audit->record('tls.dns_needed', null, ['app' => $app->slug, 'domains' => $domains, 'renewal' => $hadUsable], $app);
        $this->alerts->dnsNeeded($app, $challenge->records, $hadUsable);
    }

    private function failed(App $app, Certificate $cert, bool $hadUsable, string $reason): void
    {
        // A certificate that is still valid keeps serving while we retry.
        $cert->fill([
            'status' => $hadUsable ? CertificateStatus::Active : CertificateStatus::Failed,
            'last_error' => $reason,
        ])->save();
        $this->audit->record('tls.failed', null, ['app' => $app->slug, 'reason' => $reason], $app);
        $this->alerts->failed($app, $reason);
    }

    private function issued(App $app, Certificate $cert, CertificateInfo $info): void
    {
        $cert->fill([
            'status' => CertificateStatus::Active,
            'domains' => $info->domains,
            'staging' => $this->https->staging(),
            'issued_at' => now(),
            'expires_at' => $info->notAfter,
            'dns_pending' => null,
            'last_error' => null,
        ])->save();
        $this->alerts->issued($app);
        $this->audit->record('tls.issued', null, ['app' => $app->slug, 'domains' => $info->domains, 'staging' => $this->https->staging()], $app);

        // Switch the nginx site to HTTPS, or reload it to pick up a renewal.
        try {
            $this->routing->sync($app->refresh());
        } catch (HostOperationFailed $e) {
            Log::warning('The certificate was issued but nginx could not be updated.', ['app' => $app->slug, 'error' => $e->getMessage()]);
            $cert->fill(['last_error' => 'The certificate was issued, but nginx could not use it: '.$e->getMessage()])->save();
        }
    }
}
