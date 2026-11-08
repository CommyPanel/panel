<?php

namespace App\Tls;

use App\Alerts\CertificateAlerts;
use App\Audit\AuditLogger;
use App\Deploy\Traffic\AppRouting;
use App\Enums\CertificateStatus;
use App\Host\Data\CertificateRequest;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Certificate;
use Illuminate\Support\Facades\Log;

class IssueCertificate
{
    public function __construct(
        private readonly HostDriver $host,
        private readonly HttpsSettings $https,
        private readonly TlsPlanner $planner,
        private readonly AppRouting $routing,
        private readonly AuditLogger $audit,
        private readonly CertificateAlerts $alerts,
    ) {}

    public function __invoke(App $app): void
    {
        $domains = $this->planner->eligibleDomains($app);

        if (! $this->https->enabled() || $domains === []) {
            return;
        }

        $cert = Certificate::query()->firstOrNew(['app_id' => $app->id]);
        $hadUsable = $cert->exists && $cert->isUsable();
        $cert->fill(['status' => CertificateStatus::Issuing, 'last_attempt_at' => now()])->save();

        try {
            $info = $this->host->issueCertificate(new CertificateRequest(
                $app->slug,
                $domains,
                $this->https->email(),
                $this->https->staging(),
            ));
        } catch (HostOperationFailed $e) {
            // A certificate that is still valid keeps serving while we retry.
            $cert->fill([
                'status' => $hadUsable ? CertificateStatus::Active : CertificateStatus::Failed,
                'last_error' => $e->getMessage(),
            ])->save();
            $this->audit->record('tls.failed', null, ['app' => $app->slug, 'reason' => $e->getMessage()], $app);
            $this->alerts->failed($app, $e->getMessage());

            return;
        }

        $cert->fill([
            'status' => CertificateStatus::Active,
            'domains' => $info->domains,
            'staging' => $this->https->staging(),
            'issued_at' => now(),
            'expires_at' => $info->notAfter,
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
