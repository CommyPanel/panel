<?php

namespace App\Jobs;

use App\Deploy\Safe\RolloutService;
use App\Models\Rollout;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One look at a gradual rollout. It queues the next look itself until the rollout is over;
 * the scheduler also starts one each minute for any rollout that lost its chain.
 */
class AdvanceRolloutJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Seconds between two looks. */
    public const INTERVAL = 15;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $rolloutId) {}

    public function uniqueId(): string
    {
        return 'rollout-'.$this->rolloutId;
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        $appId = Rollout::query()->whereKey($this->rolloutId)->value('app_id');

        return [(new WithoutOverlapping("deploy-app-{$appId}"))->releaseAfter(10)->expireAfter(600)];
    }

    public function handle(RolloutService $rollouts): void
    {
        $rollout = Rollout::query()->find($this->rolloutId);

        if ($rollout === null || ! $rollout->isActive()) {
            return;
        }

        $rollouts->tick($rollout);

        if (in_array(Rollout::query()->whereKey($this->rolloutId)->value('state'), [Rollout::RUNNING, Rollout::WAITING], true)) {
            self::dispatch($this->rolloutId)->delay(now()->addSeconds(self::INTERVAL));
        }
    }
}
