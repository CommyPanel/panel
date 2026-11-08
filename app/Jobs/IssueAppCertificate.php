<?php

namespace App\Jobs;

use App\Alerts\CertificateAlerts;
use App\Enums\CertificateStatus;
use App\Models\App;
use App\Models\Certificate;
use App\Tls\IssueCertificate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class IssueAppCertificate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $appId) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("certificate-app-{$this->appId}"))->releaseAfter(30)->expireAfter(1000)];
    }

    public function handle(IssueCertificate $issue): void
    {
        $app = App::query()->find($this->appId);

        if ($app !== null) {
            $issue($app);
        }
    }

    /**
     * The worker died or timed out before the attempt could be recorded.
     */
    public function failed(?Throwable $exception): void
    {
        $interrupted = Certificate::query()
            ->where('app_id', $this->appId)
            ->where('status', CertificateStatus::Issuing)
            ->update(['status' => CertificateStatus::Failed, 'last_error' => 'The attempt was interrupted.']);

        if ($interrupted > 0 && ($app = App::query()->find($this->appId)) !== null) {
            app(CertificateAlerts::class)->failed($app, 'The attempt was interrupted.');
        }
    }
}
