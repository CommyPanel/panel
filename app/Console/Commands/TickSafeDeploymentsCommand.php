<?php

namespace App\Console\Commands;

use App\Jobs\AdvanceRolloutJob;
use App\Jobs\WatchDeploymentJob;
use App\Models\Deployment;
use App\Models\Rollout;
use Illuminate\Console\Command;

/**
 * Safety net for watches and gradual rollouts. Each one keeps itself going by queueing its
 * next look, but a restart or an error can end that chain, so every minute this queues a look
 * for any that is still open. A look that is already queued is not queued twice.
 */
class TickSafeDeploymentsCommand extends Command
{
    protected $signature = 'commypanel:tick-safe-deployments';

    protected $description = 'Queue a look at every release that is being watched and every rollout that is under way';

    public function handle(): int
    {
        foreach (Deployment::query()->where('watch_state', 'watching')->pluck('id') as $id) {
            WatchDeploymentJob::dispatch((int) $id);
        }

        foreach (Rollout::query()->whereIn('state', [Rollout::RUNNING, Rollout::WAITING])->pluck('id') as $id) {
            AdvanceRolloutJob::dispatch((int) $id);
        }

        return self::SUCCESS;
    }
}
