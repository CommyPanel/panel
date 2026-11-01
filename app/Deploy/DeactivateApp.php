<?php

namespace App\Deploy;

use App\Audit\AuditLogger;
use App\Deploy\Traffic\AppRouting;
use App\Enums\DeploymentStatus;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;

/**
 * Takes an app offline without deleting anything: the live container is
 * removed and its domains stop being served, but releases, images, domains,
 * certificates and settings stay, so the app can be activated again.
 */
class DeactivateApp
{
    public function __construct(
        private readonly HostDriver $host,
        private readonly AppRouting $routing,
        private readonly Deployer $deployer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws DeploymentFailed
     */
    public function deactivate(App $app, ?User $by = null): void
    {
        $live = $app->activeDeployment()->first();

        if ($live === null) {
            throw new DeploymentFailed('This app is not running.');
        }

        if ($live->container_name !== null) {
            try {
                $this->host->removeContainer($live->container_name);
            } catch (HostOperationFailed $e) {
                throw new DeploymentFailed("Could not stop the container: {$e->getMessage()}");
            }
        }

        $live->forceFill(['status' => DeploymentStatus::Stopped])->save();
        $app->forceFill(['active_deployment_id' => null, 'stopped_at' => null])->save();
        $this->routing->sync($app->refresh());

        $this->audit->record('app.deactivated', $by, ['app' => $app->slug], $app);
    }

    /**
     * Start the release that was live before, without rebuilding it.
     *
     * @throws DeploymentFailed
     */
    public function activate(App $app, ?User $by = null): Deployment
    {
        $stopped = $app->deployments()->where('status', DeploymentStatus::Stopped)->latest('number')->first();

        if ($stopped === null) {
            throw new DeploymentFailed('There is no stopped release to start. Press Deploy to build one.');
        }

        $deployment = $this->deployer->rollback($app, $stopped, $by);
        $this->audit->record('app.activated', $by, ['app' => $app->slug, 'release' => $stopped->number], $app);

        return $deployment;
    }
}
