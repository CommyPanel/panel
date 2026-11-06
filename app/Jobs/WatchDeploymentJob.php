<?php

namespace App\Jobs;

use App\Deploy\Safe\WatchService;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One look at a release that is being watched after it went live. It queues the next look
 * itself, 15 seconds on, until the watch is over; the scheduler also starts one each minute
 * for any watch that lost its chain (a restart, an error), and being unique until it starts
 * keeps those from piling up.
 */
class WatchDeploymentJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Seconds between two looks. */
    public const INTERVAL = 15;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $deploymentId) {}

    public function uniqueId(): string
    {
        return 'watch-'.$this->deploymentId;
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        $appId = Deployment::query()->whereKey($this->deploymentId)->value('app_id');

        // Never at the same time as a deployment of the same app.
        return [(new WithoutOverlapping("deploy-app-{$appId}"))->releaseAfter(10)->expireAfter(600)];
    }

    public function handle(WatchService $watch): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);

        if ($deployment === null || $deployment->watch_state !== 'watching') {
            return;
        }

        $watch->tick($deployment);

        if (Deployment::query()->whereKey($this->deploymentId)->value('watch_state') === 'watching') {
            self::dispatch($this->deploymentId)->delay(now()->addSeconds(self::INTERVAL));
        }
    }
}
