<?php

namespace App\Jobs;

use App\Alerts\CertificateAlerts;
use App\Dns\PanelDomain;
use App\Tls\IssuePanelCertificate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class IssuePanelCertificateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('certificate-panel'))->releaseAfter(30)->expireAfter(1000)];
    }

    public function handle(IssuePanelCertificate $issue): void
    {
        $issue();
    }

    public function failed(?Throwable $exception): void
    {
        $panel = app(PanelDomain::class);

        if ($panel->certificateStatus() === 'issuing') {
            $panel->markFailed('The attempt was interrupted.', false);
            app(CertificateAlerts::class)->failed(null, 'The attempt was interrupted.');
        }
    }
}
