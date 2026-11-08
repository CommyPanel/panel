<?php

namespace App\Jobs;

use App\Alerts\DeployAlerts;
use App\Deploy\DeployApp;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class RunDeployment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $deploymentId) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        $appId = Deployment::query()->whereKey($this->deploymentId)->value('app_id');

        // One deployment per app at a time; others wait their turn.
        return [(new WithoutOverlapping("deploy-app-{$appId}"))->releaseAfter(15)->expireAfter(3700)];
    }

    public function handle(DeployApp $deploy): void
    {
        $deploy(Deployment::query()->findOrFail($this->deploymentId));
    }

    /**
     * The worker died or the job timed out before DeployApp could record it.
     */
    public function failed(?Throwable $exception): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);

        if ($deployment !== null && ! $deployment->status->isFinished()) {
            $deployment->forceFill([
                'status' => DeploymentStatus::Failed,
                'error' => 'The deployment was interrupted.',
                'finished_at' => now(),
            ])->save();
            DeployAlerts::dispatchFor($deployment);
        }
    }
}
