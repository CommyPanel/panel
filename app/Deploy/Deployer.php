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
