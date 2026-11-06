<?php

namespace App\Deploy;

use App\Audit\AuditLogger;
use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Entry point for starting deployments and rollbacks; the work itself runs
 * in the queue.
 */
class Deployer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function deploy(App $app, ?User $by = null): Deployment
    {
        $deployment = $this->create($app, ['release_command' => $app->release_command]);

        $this->audit->record('deploy.queued', $by, ['app' => $app->slug, 'deployment' => $deployment->number], $app);
        RunDeployment::dispatch($deployment->id);

        return $deployment;
    }

    /**
     * Redeploy an earlier release without rebuilding it.
     *
     * @throws DeploymentFailed
     */
    public function rollback(App $app, Deployment $target, ?User $by = null): Deployment
    {
        if ($target->app_id !== $app->id || $target->image_tag === null || ! $target->image_available) {
            throw new DeploymentFailed('That release is no longer available to roll back to.');
        }

        if ($target->id === $app->active_deployment_id) {
            throw new DeploymentFailed('That release is already live.');
        }

        $deployment = $this->create($app, [
            'image_tag' => $target->image_tag,
            'commit_sha' => $target->commit_sha,
            'commit_message' => $target->commit_message,
            'rolled_back_from_id' => $target->id,
        ]);

        $this->audit->record('deploy.rollback_queued', $by, [
            'app' => $app->slug,
            'deployment' => $deployment->number,
            'to_release' => $target->number,
        ], $app);
        RunDeployment::dispatch($deployment->id);

        return $deployment;
    }

    /**
     * Go live again with a release that was rolled back automatically, because a person wants
     * to try again. Nothing is built: the image is still there. `$watch` says whether the
     * app's usual watch (and its automatic rollback) applies this time.
     *
     * @throws DeploymentFailed
     */
    public function retry(App $app, Deployment $rolledBack, ?User $by = null, bool $watch = true): Deployment
    {
        if ($rolledBack->app_id !== $app->id || $rolledBack->status !== DeploymentStatus::RolledBack) {
            throw new DeploymentFailed('Only a release that was rolled back automatically can be tried again this way.');
        }

        if ($rolledBack->image_tag === null || ! $rolledBack->image_available) {
            throw new DeploymentFailed('That release\'s image is no longer available. Deploy the app again instead.');
        }

        $deployment = $this->create($app, [
            'image_tag' => $rolledBack->image_tag,
            'commit_sha' => $rolledBack->commit_sha,
            'commit_message' => $rolledBack->commit_message,
            'rolled_back_from_id' => $rolledBack->id,
            'retry' => true,
            'skip_watch' => ! $watch,
        ]);

        $this->audit->record('deploy.retry_queued', $by, [
            'app' => $app->slug,
            'deployment' => $deployment->number,
            'release' => $rolledBack->number,
            'watched' => $watch,
        ], $app);
        RunDeployment::dispatch($deployment->id);

        return $deployment;
    }

    /**
     * Recreate the live container from the image that is already running, so that changed
     * variables, volumes and links take effect. Nothing is fetched or built; the new container
     * is started next to the old one, health-checked and given the traffic before the old one
     * stops, like any deployment, so there is no downtime and a failure keeps the old one.
     *
     * @throws DeploymentFailed
     */
    public function apply(App $app, ?User $by = null): Deployment
    {
        $live = $app->activeDeployment;

        if ($live === null || $live->image_tag === null || ! $live->image_available) {
            throw new DeploymentFailed('There is no running version to apply the changes to. Deploy the app instead.');
        }

        $deployment = $this->create($app, [
            'image_tag' => $live->image_tag,
            'commit_sha' => $live->commit_sha,
            'commit_message' => $live->commit_message,
            'rolled_back_from_id' => $live->id,
            'settings_only' => true,
        ]);

        $this->audit->record('deploy.apply_queued', $by, ['app' => $app->slug, 'deployment' => $deployment->number], $app);
        RunDeployment::dispatch($deployment->id);

        return $deployment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(App $app, array $attributes): Deployment
    {
        return DB::transaction(fn () => $app->deployments()->create([
            ...$attributes,
            'number' => $app->nextDeploymentNumber(),
            'status' => DeploymentStatus::Queued,
        ]));
    }
}
