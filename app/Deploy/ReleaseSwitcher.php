<?php

namespace App\Deploy;

use App\Databases\AppLinks;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\DeploymentStage;
use App\Enums\DeploymentStatus;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\WatchDeploymentJob;
use App\Models\App;
use App\Models\Deployment;
use App\Tls\TlsPlanner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * The one place that moves an app's traffic from one release to another and cleans up the
 * old one: a normal deployment, a rollback, the end of a gradual rollout and an automatic
 * rollback all go through it, so they behave the same.
 */
class ReleaseSwitcher
{
    public function __construct(
        private readonly HostDriver $host,
        private readonly TrafficSwitcher $traffic,
        private readonly AppLinks $links,
        private readonly TlsPlanner $tls,
    ) {}

    /**
     * Make `$new` the live release: all traffic goes to it, it takes the name other apps use,
     * and the release it replaces is either retired now or, when the app watches new releases,
     * kept running for a while so traffic can go straight back to it.
     */
    public function goLive(App $app, Deployment $new, ?Deployment $previous, DeployLogger $log, bool $mayWatch = true): void
    {
        $new->forceFill(['stage' => DeploymentStage::Switch])->save();
        $log->step('Switching traffic to the new version.');
        $this->switchTo($app, $new, $previous, $log);
        $new->forceFill(['status' => DeploymentStatus::Active, 'stage' => DeploymentStage::Done, 'finished_at' => now(), 'live_at' => now()])->save();

        $this->tls->ensure($app);

        if ($mayWatch && $this->shouldWatch($app, $new, $previous)) {
            $this->beginWatch($app, $new, $previous, $log);
        } else {
            $this->retire($previous, $log);
        }

        $this->pruneImages($app, $log);
    }

    /**
     * Point nginx, the link names and the app's record at `$to`, which replaces `$from`.
     */
    public function switchTo(App $app, Deployment $to, ?Deployment $from, DeployLogger $log): void
    {
        $this->traffic->switchTo($to);
        $this->moveLinkNames($app, $to, $from, $log);
        $app->forceFill(['active_deployment_id' => $to->id, 'stopped_at' => null])->save();
    }

    /**
     * Whether the release it replaces should be kept running while the new one is watched.
     * Nothing to watch without a container to go back to, or without a domain (no visitors).
     */
    public function shouldWatch(App $app, Deployment $new, ?Deployment $previous): bool
    {
        return $app->watch_enabled
            && ! $new->skip_watch
            && $previous !== null
            && $previous->container_name !== null
            && $app->domains()->exists();
    }

    private function beginWatch(App $app, Deployment $new, Deployment $previous, DeployLogger $log): void
    {
        $minutes = max(1, (int) $app->watch_minutes);
        $new->forceFill([
            'watch_state' => 'watching',
            'watch_until' => now()->addMinutes($minutes),
            'watch_previous_id' => $previous->id,
            'watch_strikes' => 0,
            'watch_note' => null,
        ])->save();
        // It no longer serves anyone, but its container stays until the watch ends.
        $previous->forceFill(['status' => DeploymentStatus::Superseded])->save();

        $log->step("Watching the new version for {$minutes} minute".($minutes === 1 ? '' : 's').". The previous version ({$previous->container_name}) keeps running so traffic can go straight back to it.");
        WatchDeploymentJob::dispatch($new->id)->delay(now()->addSeconds(WatchDeploymentJob::INTERVAL));
    }

    /**
     * Hand the name other apps call this app by from one release to another. The new release
     * joins first, so the name never points at nothing; the old one leaves right after, so it
     * does not keep answering while it drains.
     */
    public function moveLinkNames(App $app, Deployment $to, ?Deployment $from, DeployLogger $log): void
    {
        $networks = $this->links->providedNetworks($app);

        if ($networks === []) {
            return;
        }

        $log->step('Moving the name other apps use for this app to the new version.');

        foreach ($networks as $network) {
            $this->host->connectNetwork((string) $to->container_name, $network->name, $network->aliases);
        }

        if ($from === null || $from->container_name === null) {
            return;
        }

        foreach ($networks as $network) {
            try {
                $this->host->disconnectNetwork($from->container_name, $network->name);
            } catch (HostOperationFailed $e) {
                // It keeps the name until it stops a few seconds from now; not worth failing over.
                $log->line("Warning: could not take the old version off {$network->name}: ".$e->getMessage());
            }
        }
    }

    /**
     * Stop and remove a release's container after letting its open requests finish.
     */
    public function retire(?Deployment $release, DeployLogger $log, DeploymentStatus $final = DeploymentStatus::Superseded): void
    {
        if ($release === null || $release->container_name === null) {
            return;
        }

        $drain = (int) config('commypanel.deploy.drain_seconds');
        $log->step("Stopping the previous version ({$release->container_name}) in {$drain}s.");
        Sleep::for($drain)->seconds();

        try {
            $this->host->stopContainer($release->container_name);
            $this->host->removeContainer($release->container_name);
        } catch (HostOperationFailed $e) {
            // The other version is already serving; do not fail over cleanup.
            Log::warning('Could not retire the previous container.', ['container' => $release->container_name, 'error' => $e->getMessage()]);
            $log->line('Warning: could not stop the previous container: '.$e->getMessage());
        }

        $release->forceFill(['status' => $final])->save();
    }

    /**
     * Keep the newest releases' images for rollback and remove the rest.
     */
    public function pruneImages(App $app, DeployLogger $log): void
    {
        $keep = max(1, (int) config('commypanel.deploy.keep_images'));

        /** @var list<string> $tags newest release first */
        $tags = $app->deployments()
            ->where('image_available', true)
            ->whereNotNull('image_tag')
            ->selectRaw('image_tag, max(number) as newest')
            ->groupBy('image_tag')
            ->orderByDesc('newest')
            ->pluck('image_tag')
            ->all();

        foreach (array_slice($tags, $keep) as $tag) {
            try {
                $this->host->removeImage($tag);
                $app->deployments()->where('image_tag', $tag)->update(['image_available' => false]);
                $log->line("Removed old image {$tag}.");
            } catch (HostOperationFailed $e) {
                Log::warning('Could not remove an old image.', ['tag' => $tag, 'error' => $e->getMessage()]);
            }
        }
    }
}
