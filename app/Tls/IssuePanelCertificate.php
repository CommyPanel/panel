<?php

namespace App\Tls;

use App\Alerts\CertificateAlerts;
use App\Audit\AuditLogger;
use App\Dns\PanelDomain;
use App\Host\Data\CertificateRequest;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class IssuePanelCertificate
{
    public function __construct(
        private readonly HostDriver $host,
        private readonly HttpsSettings $https,
        private readonly PanelDomain $panel,
        private readonly PanelRouting $routing,
        private readonly AuditLogger $audit,
        private readonly CertificateAlerts $alerts,
    ) {}

    public function __invoke(): void
    {
        $domain = $this->panel->name();

        if ($domain === null || ! $this->https->enabled() || ! $this->panel->dnsVerified()) {
            return;
        }

        $hadUsable = $this->panel->certificateUsable();
        $this->panel->markIssuing();

        try {
            $info = $this->host->issueCertificate(new CertificateRequest(PanelDomain::SLUG, [$domain], $this->https->email(), $this->https->staging()));
        } catch (HostOperationFailed $e) {
            $this->panel->markFailed($e->getMessage(), $hadUsable);
            $this->audit->record('tls.failed', null, ['app' => PanelDomain::SLUG, 'reason' => $e->getMessage()]);
            $this->alerts->failed(null, $e->getMessage());

            return;
        }

        $this->panel->markIssued(Carbon::instance($info->notAfter));
        $this->alerts->issued(null);
        $this->audit->record('tls.issued', null, ['app' => PanelDomain::SLUG, 'domains' => $info->domains, 'staging' => $this->https->staging()]);

        try {
            $this->routing->sync();
        } catch (HostOperationFailed $e) {
            Log::warning('The panel certificate was issued but nginx could not be updated.', ['error' => $e->getMessage()]);
            $this->panel->markFailed('The certificate was issued, but nginx could not use it: '.$e->getMessage(), true);
        }
    }

    /**
     * Whether a certificate should be requested now: none yet, expiring soon,
     * or a failure that has waited long enough.
     */
    public function needed(): bool
    {
        if ($this->panel->name() === null || ! $this->https->enabled() || ! $this->panel->dnsVerified()) {
            return false;
        }

        $status = $this->panel->certificateStatus();
        $attempt = $this->panel->lastAttemptAt();

        if ($status === 'issuing' && $attempt?->isAfter(now()->subMinutes(15))) {
            return false;
        }

        if ($status === 'failed' && $attempt?->isAfter(now()->subHours((int) config('commypanel.https.retry_after_hours')))) {
            return false;
        }

        $expires = $this->panel->certificateExpiresAt();

        return $status !== 'active' || $expires === null
            || $expires->isBefore(now()->addDays((int) config('commypanel.https.renew_before_days')));
    }
}
