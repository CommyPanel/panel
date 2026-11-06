<?php

namespace App\Deploy\Safe;

use App\Audit\AuditLogger;
use App\Deploy\DeployLogger;
use App\Deploy\ReleaseSwitcher;
use App\Enums\DeploymentStatus;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\SendDeployAlert;
use App\Models\App;
use App\Models\Deployment;
use App\Traffic\TrafficJudge;
use App\Traffic\TrafficMonitor;
use App\Traffic\Verdict;

/**
 * Watches a release after it went live. The release it replaced is kept running, so if the
 * requests show the new one doing clearly worse (or its container stops), traffic goes
 * straight back, with nothing to build or start. If it holds up, the old container is
 * stopped when the watch ends. A person can always end the watch, roll back by hand, or
 * (after an automatic rollback) try the release again.
 */
class WatchService
{
    /** How many looks in a row must find the release worse before it is rolled back. */
    public const STRIKES = 2;

    /** How far back the release it replaced is measured, to compare with. */
    public const REFERENCE_SECONDS = 600;

    public function __construct(
        private readonly HostDriver $host,
        private readonly ReleaseSwitcher $switcher,
        private readonly TrafficMonitor $monitor,
        private readonly TrafficJudge $judge,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * One look at a watched release: decide to roll back, carry on, or finish the watch.
     */
    public function tick(Deployment $new): void
    {
        $new->refresh();

        if ($new->watch_state !== 'watching') {
            return;
        }

        $app = $new->app;
        $previous = $new->watch_previous_id === null ? null : Deployment::query()->find($new->watch_previous_id);

        // Something else took over (the app was stopped, or a newer release went live): nothing to protect.
        if ($app->active_deployment_id !== $new->id) {
            $this->end($new, $previous, 'ended', 'The release is no longer the live one.');

            return;
        }

        if (! $this->isRunning($new)) {
            $this->rollBack($new, $previous, 'The new version stopped running.');

            return;
        }

        $verdict = $this->look($app, $new, $previous);
        $over = now()->greaterThanOrEqualTo($new->watch_until ?? now());

        if ($verdict->isBad()) {
            $strikes = $new->watch_strikes + 1;
            $new->forceFill(['watch_strikes' => $strikes, 'watch_note' => mb_substr($verdict->reason, 0, 300)])->save();

            if ($strikes >= self::STRIKES || $over) {
                $this->rollBack($new, $previous, $verdict->reason);
            }

            return;
        }

        $new->forceFill(['watch_strikes' => 0, 'watch_note' => mb_substr($verdict->reason, 0, 300)])->save();

        if ($over) {
            $this->end($new, $previous, $verdict->isOk() ? 'passed' : 'ended', $verdict->isOk() ? $verdict->reason : 'Not enough traffic to judge it, so it was accepted. '.$verdict->reason);
        }
    }

    /**
     * What the requests since the release went live say, against the release before it.
     */
    public function look(App $app, Deployment $new, ?Deployment $previous): Verdict
    {
        $now = (int) now()->timestamp;
        $liveAt = $new->live_at === null ? $now : (int) $new->live_at->timestamp;
        $stat = $this->monitor->stat($app, $new, $liveAt, $now + 1);
        $reference = $previous === null ? null : $this->monitor->stat($app, $previous, $liveAt - self::REFERENCE_SECONDS, $liveAt);

        return $this->judge->judge($stat, $reference);
    }

    /**
     * Put traffic back on the release the new one replaced.
     */
    public function rollBack(Deployment $new, ?Deployment $previous, string $reason): void
    {
        $app = $new->app;
        $log = new DeployLogger($new->fresh() ?? $new);

        if ($previous === null || $previous->container_name === null || ! $this->isRunning($previous)) {
            $note = 'It did worse than the previous version, but that one is no longer running, so traffic could not be moved back. '.$reason;
            $new->forceFill(['watch_state' => 'ended', 'watch_note' => mb_substr($note, 0, 300)])->save();
            $log->step($note);
            $this->audit->record('deploy.auto_rollback_failed', null, ['app' => $app->slug, 'deployment' => $new->number, 'reason' => $reason], $app);

            return;
        }

        $log->step("Rolling back automatically: {$reason}");
        $this->switcher->switchTo($app, $previous, $new, $log);
        $previous->forceFill(['status' => DeploymentStatus::Active])->save();
        $new->forceFill([
            'status' => DeploymentStatus::RolledBack,
            'error' => $reason,
            'watch_state' => 'rolled_back',
            'watch_note' => mb_substr($reason, 0, 300),
            'finished_at' => now(),
        ])->save();
        $this->switcher->retire($new, $log, DeploymentStatus::RolledBack);
        $log->line("Traffic is back on release #{$previous->number}.");
        $log->flush();

        $this->audit->record('deploy.auto_rolled_back', null, [
            'app' => $app->slug,
            'deployment' => $new->number,
            'back_to' => $previous->number,
            'reason' => $reason,
        ], $app);
        SendDeployAlert::dispatchFor($new);
    }

    /**
     * End the watch of an app early (a new deployment is starting, or a person asked): the new
     * release is accepted and the container it replaced is stopped.
     */
    public function finish(App $app, string $note = 'The watch was ended early.'): void
    {
        foreach ($app->deployments()->where('watch_state', 'watching')->get() as $watched) {
            $previous = $watched->watch_previous_id === null ? null : Deployment::query()->find($watched->watch_previous_id);
            $this->end($watched, $previous, 'ended', $note);
        }
    }

    private function end(Deployment $new, ?Deployment $previous, string $state, string $note): void
    {
        $log = new DeployLogger($new->fresh() ?? $new);
        $new->forceFill(['watch_state' => $state, 'watch_note' => mb_substr($note, 0, 300)])->save();
        $log->step($state === 'passed' ? "The watch is over and the new version held up. {$note}" : "The watch is over. {$note}");
        $this->switcher->retire($previous, $log);
        $log->flush();

        if ($state === 'passed') {
            $this->audit->record('deploy.watch_passed', null, ['app' => $new->app->slug, 'deployment' => $new->number], $new->app);
        }
    }

    private function isRunning(Deployment $deployment): bool
    {
        if ($deployment->container_name === null) {
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
