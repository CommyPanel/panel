<?php

namespace App\Deploy;

use App\Audit\AuditLogger;
use App\Host\Exceptions\DockerUnavailable;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;

/**
 * Stops, starts and restarts the live release's container. Unlike
 * deactivating, nothing is removed: the release stays live and its domains
 * stay configured (visitors get an error page while the app is stopped), so
 * starting it again is instant and needs no deployment.
 */
class ControlApp
{
    public function __construct(
        private readonly HostDriver $host,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws DeploymentFailed
     */
    public function stop(App $app, ?User $by = null): void
    {
        $container = $this->liveContainer($app);

        if ($app->stopped_at !== null) {
            throw new DeploymentFailed(__('The app is already stopped.'));
        }

        $this->attempt(__('Could not stop the app: :reason'), __('Could not stop the app: Docker is not running on this server. See Server > Docker.'), fn () => $this->host->stopContainer($container));
        $app->forceFill(['stopped_at' => now()])->save();

        $this->audit->record('app.stopped', $by, ['app' => $app->slug], $app);
    }

    /**
     * @throws DeploymentFailed
     */
    public function start(App $app, ?User $by = null): void
    {
        $container = $this->liveContainer($app);

        if ($app->stopped_at === null) {
            throw new DeploymentFailed(__('The app is already running.'));
        }

        $this->attempt(__('Could not start the app: :reason'), __('Could not start the app: Docker is not running on this server. See Server > Docker.'), fn () => $this->host->startContainer($container));
        $app->forceFill(['stopped_at' => null])->save();

        $this->audit->record('app.started', $by, ['app' => $app->slug], $app);
    }

    /**
     * @throws DeploymentFailed
     */
    public function restart(App $app, ?User $by = null): void
    {
        $container = $this->liveContainer($app);

        if ($app->stopped_at !== null) {
            throw new DeploymentFailed(__('The app is stopped. Start it instead.'));
        }

        $this->attempt(__('Could not restart the app: :reason'), __('Could not restart the app: Docker is not running on this server. See Server > Docker.'), fn () => $this->host->restartContainer($container));

        $this->audit->record('app.restarted', $by, ['app' => $app->slug], $app);
    }

    /**
     * @throws DeploymentFailed
     */
    private function liveContainer(App $app): string
    {
        if ($app->isDeploying()) {
            throw new DeploymentFailed(__('Wait for the running deployment to finish first.'));
        }

        /** @var Deployment|null $live */
        $live = $app->activeDeployment()->first();

        if ($live === null || $live->container_name === null) {
            throw new DeploymentFailed(__('This app has no live release. Deploy it first.'));
        }

        return $live->container_name;
    }

    /**
     * @param  callable(): void  $operation
     *
     * @throws DeploymentFailed
     */
    /**
     * @param  string  $failure  shown if it fails, with `:reason` for what the host said
     * @param  string  $dockerDown  shown if Docker is not running
     */
    private function attempt(string $failure, string $dockerDown, callable $operation): void
    {
        try {
            $operation();
        } catch (DockerUnavailable $e) {
            throw new DeploymentFailed($dockerDown, 0, $e);
        } catch (HostOperationFailed $e) {
            throw new DeploymentFailed(strtr($failure, [':reason' => $e->getMessage()]), 0, $e);
        }
    }
}
