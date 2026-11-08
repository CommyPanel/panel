<?php

namespace App\Deploy\Safe;

use App\Alerts\DeployAlerts;
use App\Audit\AuditLogger;
use App\Deploy\DeployLogger;
use App\Deploy\ReleaseSwitcher;
use App\Deploy\Traffic\AppRouting;
use App\Enums\DeploymentStage;
use App\Enums\DeploymentStatus;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\AdvanceRolloutJob;
use App\Models\App;
use App\Models\Deployment;
use App\Models\Rollout;
use App\Models\User;
use App\Traffic\TrafficJudge;
use App\Traffic\TrafficMonitor;
use App\Traffic\Verdict;

/**
 * A gradual rollout. The new release is started next to the live one and health-checked as
 * usual, but instead of taking all the traffic it gets a share (10%, then 50%, ...) for a
 * while at each step. At every step the requests it served are compared with the ones the
 * old release served over the same time. If it holds up it moves to the next step, and after
 * the last one everybody gets it and the old container stops. If it does clearly worse, its
 * share goes back to the old release and it is stopped. When there is too little traffic to
 * tell, it asks a person instead of guessing.
 *
 * Visitors are given a bucket once (kept in a cookie), so each stays on one version and a
 * higher share only moves the buckets in between.
 */
class RolloutService
{
    /** How many looks in a row must find it worse before it is rolled back. */
    public const STRIKES = 2;

    /** A step with too little traffic is held this many times longer, then a person is asked. */
    public const WAIT_FACTOR = 3;

    public const DEFAULT_STEPS = [10, 50];

    public function __construct(
        private readonly HostDriver $host,
        private readonly ReleaseSwitcher $switcher,
        private readonly AppRouting $routing,
        private readonly TrafficMonitor $monitor,
        private readonly TrafficJudge $judge,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The shares an app rolls out in, cleaned up: whole numbers from 1 to 99, rising, at most four.
     *
     * @return list<int>
     */
    public static function stepsFor(App $app): array
    {
        $steps = array_values(array_unique(array_filter(
            array_map(intval(...), (array) ($app->rollout_steps ?? [])),
            fn (int $p) => $p >= 1 && $p <= 99,
        )));
        sort($steps);

        return $steps === [] ? self::DEFAULT_STEPS : array_slice($steps, 0, 4);
    }

    /**
     * Whether this deployment should be rolled out gradually rather than switched at once.
     * A rollback, an apply and a retry are what a person asked for right now, so they are not.
     */
    public function shouldRoll(App $app, Deployment $new, ?Deployment $previous): bool
    {
        return $app->rollout_enabled
            && $previous !== null
            && $previous->container_name !== null
            && $new->rolled_back_from_id === null
            && ! $new->settings_only
            && $app->domains()->exists();
    }

    public function start(App $app, Deployment $new, Deployment $previous, DeployLogger $log): Rollout
    {
        $steps = self::stepsFor($app);
        $hold = max(1, (int) $app->rollout_hold_minutes);

        $rollout = Rollout::query()->create([
            'app_id' => $app->id,
            'deployment_id' => $new->id,
            'previous_deployment_id' => $previous->id,
            'steps' => $steps,
            'step_index' => 0,
            'percent' => $steps[0],
            'hold_minutes' => $hold,
            'auto' => $app->rollout_auto,
            'state' => Rollout::RUNNING,
            'step_started_at' => now(),
        ]);

        $new->forceFill(['status' => DeploymentStatus::Rollout, 'stage' => DeploymentStage::Switch])->save();
        $this->routing->sync($app);

        $path = implode('% → ', $steps).'% → 100%';
        $log->step("Gradual rollout: {$path}. {$steps[0]}% of visitors now get the new version, and it is held for {$hold} minute".($hold === 1 ? '' : 's').' at each step.');
        $log->line($rollout->auto ? 'It moves on by itself while the requests look healthy.' : 'It waits for a person to move on after each step.');
        $log->flush();

        $this->audit->record('deploy.rollout_started', null, ['app' => $app->slug, 'deployment' => $new->number, 'steps' => $steps, 'hold_minutes' => $hold], $app);
        AdvanceRolloutJob::dispatch($rollout->id)->delay(now()->addSeconds(AdvanceRolloutJob::INTERVAL));

        return $rollout;
    }

    /**
     * One look at a rollout: roll back, carry on, wait for a person, or move to the next step.
     */
    public function tick(Rollout $rollout): void
    {
        $rollout->refresh();

        if (! $rollout->isActive()) {
            return;
        }

        $new = $rollout->deployment;
        $previous = $rollout->previous;

        if (! $this->isRunning($new)) {
            $this->abort($rollout, null, 'The new version stopped running.', automatic: true);

            return;
        }

        if ($previous === null || ! $this->isRunning($previous)) {
            // The old version is gone, so its share of the visitors gets errors: the new one has to take them all.
            $this->complete($rollout, 'The previous version stopped running, so everyone moved to the new one.');

            return;
        }

        if ($rollout->state === Rollout::WAITING) {
            return;
        }

        $verdict = $this->look($rollout);
        $elapsed = (int) now()->timestamp - (int) $rollout->step_started_at->timestamp;
        $hold = $rollout->hold_minutes * 60;

        if ($verdict->isBad()) {
            $strikes = $rollout->strikes + 1;
            $rollout->forceFill(['strikes' => $strikes, 'note' => mb_substr($verdict->reason, 0, 300)])->save();

            if ($strikes >= self::STRIKES) {
                $this->abort($rollout, null, $verdict->reason, automatic: true);
            }

            return;
        }

        $rollout->forceFill(['strikes' => 0, 'note' => mb_substr($verdict->reason, 0, 300)])->save();

        if ($elapsed < $hold) {
            return;
        }

        if ($verdict->isOk()) {
            $rollout->auto ? $this->advance($rollout) : $this->wait($rollout, 'This step looks healthy. Move on, or abort. '.$verdict->reason);

            return;
        }

        if ($elapsed >= $hold * self::WAIT_FACTOR) {
            $this->wait($rollout, 'There was not enough traffic to decide. Move on, or abort. '.$verdict->reason);
        }
    }

    /**
     * What the requests of this step say about the new release, against the old one over the same time.
     */
    public function look(Rollout $rollout): Verdict
    {
        $new = $rollout->deployment;
        $previous = $rollout->previous;
        $stats = $this->monitor->ranges($rollout->app, (int) $rollout->step_started_at->timestamp, (int) now()->timestamp + 1);

        return $this->judge->judge(
            $new->host_port === null ? null : ($stats[$new->host_port] ?? null),
            $previous === null || $previous->host_port === null ? null : ($stats[$previous->host_port] ?? null),
        );
    }

    /**
     * Give the new release the next share, or everyone after the last step.
     */
    public function advance(Rollout $rollout, ?User $by = null): void
    {
        $rollout->refresh();

        if (! $rollout->isActive()) {
            return;
        }

        $next = $rollout->step_index + 1;

        if ($next >= count($rollout->steps)) {
            $this->complete($rollout, $by === null ? 'The last step held up.' : "{$by->name} moved it on.", $by);

            return;
        }

        $percent = $rollout->steps[$next];
        $rollout->forceFill([
            'step_index' => $next,
            'percent' => $percent,
            'state' => Rollout::RUNNING,
            'step_started_at' => now(),
            'strikes' => 0,
            'note' => null,
        ])->save();

        $this->routing->sync($rollout->app);
        $log = new DeployLogger($rollout->deployment->fresh() ?? $rollout->deployment);
        $log->step("Rollout: {$percent}% of visitors now get the new version.");
        $log->flush();
        $this->audit->record('deploy.rollout_advanced', $by, ['app' => $rollout->app->slug, 'deployment' => $rollout->deployment->number, 'percent' => $percent], $rollout->app);
    }

    /**
     * Everyone gets the new release now, and the old container stops.
     */
    public function complete(Rollout $rollout, string $note = 'Promoted.', ?User $by = null): void
    {
        $rollout->refresh();

        if (! $rollout->isActive()) {
            return;
        }

        $app = $rollout->app;
        $new = $rollout->deployment;
        $previous = $rollout->previous;
        // Recorded first, so the site nginx is given has no split in it.
        $rollout->forceFill(['state' => Rollout::PROMOTED, 'percent' => 100, 'note' => mb_substr($note, 0, 300), 'finished_at' => now()])->save();

        $log = new DeployLogger($new->fresh() ?? $new);
        $log->step("Rollout finished: {$note} Everyone gets the new version.");
        $this->switcher->goLive($app, $new, $previous, $log, mayWatch: false);
        $log->line('Deployment finished.');
        $log->flush();

        $this->audit->record('deploy.rollout_promoted', $by, ['app' => $app->slug, 'deployment' => $new->number], $app);
        $this->audit->record('deploy.succeeded', null, ['app' => $app->slug, 'deployment' => $new->number], $app);
        DeployAlerts::dispatchFor($new);
    }

    /**
     * Take the new release's share away and stop it. Automatic when the requests were clearly
     * worse; otherwise a person decided.
     */
    public function abort(Rollout $rollout, ?User $by, string $reason, bool $automatic = false): void
    {
        $rollout->refresh();

        if (! $rollout->isActive()) {
            return;
        }

        $app = $rollout->app;
        $new = $rollout->deployment;
        $rollout->forceFill([
            'state' => $automatic ? Rollout::ROLLED_BACK : Rollout::ABORTED,
            'note' => mb_substr($reason, 0, 300),
            'finished_at' => now(),
        ])->save();

        $log = new DeployLogger($new->fresh() ?? $new);
        $log->step($automatic ? "Rolling back the rollout automatically: {$reason}" : 'Rollout aborted'.($by === null ? '' : " by {$by->name}").'.');
        // The site goes back to serving only the old release, which was never replaced.
        $this->routing->sync($app);
        $new->forceFill([
            'status' => DeploymentStatus::RolledBack,
            'error' => $automatic ? $reason : 'The rollout was aborted'.($by === null ? '.' : " by {$by->name}."),
            'finished_at' => now(),
        ])->save();
        $this->switcher->retire($new, $log, DeploymentStatus::RolledBack);
        $log->line('All traffic is on the previous version.');
        $log->flush();

        $this->audit->record($automatic ? 'deploy.rollout_rolled_back' : 'deploy.rollout_aborted', $by, ['app' => $app->slug, 'deployment' => $new->number, 'reason' => $reason], $app);

        if ($automatic) {
            DeployAlerts::dispatchFor($new);
        }
    }

    private function wait(Rollout $rollout, string $note): void
    {
        $rollout->forceFill(['state' => Rollout::WAITING, 'note' => mb_substr($note, 0, 300)])->save();
        $log = new DeployLogger($rollout->deployment->fresh() ?? $rollout->deployment);
        $log->step("Rollout waiting for a person: {$note}");
        $log->flush();
        $this->audit->record('deploy.rollout_waiting', null, ['app' => $rollout->app->slug, 'deployment' => $rollout->deployment->number, 'percent' => $rollout->percent], $rollout->app);
    }

    private function isRunning(?Deployment $deployment): bool
    {
        if ($deployment === null || $deployment->container_name === null) {
            return false;
        }

        try {
            foreach ($this->host->listContainers() as $container) {
                if ($container->name === $deployment->container_name) {
                    return $container->state === 'running';
                }
            }
        } catch (HostOperationFailed) {
            return true; // Docker cannot be asked: do not blame the release for that
        }

        return false;
    }
}
