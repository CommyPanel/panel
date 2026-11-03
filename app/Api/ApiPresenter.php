<?php

namespace App\Api;

use App\Models\App;
use App\Models\Deployment;

/**
 * What the API says about apps and deployments. Nothing here is a secret: no
 * environment variables, keys, tokens or webhook secrets.
 */
class ApiPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function app(App $app): array
    {
        $app->loadMissing(['latestDeployment', 'activeDeployment', 'domains']);

        return [
            'slug' => $app->slug,
            'name' => $app->name,
            'status' => $app->status()->value,
            'stack' => $app->stack?->value,
            'domains' => $app->domains->pluck('name')->values()->all(),
            'live_deployment' => $app->activeDeployment?->number,
            'latest_deployment' => $app->latestDeployment === null ? null : self::deployment($app, $app->latestDeployment),
            'created_at' => $app->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deployment(App $app, Deployment $deployment, bool $withLog = false): array
    {
        return [
            'number' => $deployment->number,
            'status' => $deployment->status->value,
            'stage' => $deployment->stage?->value,
            'finished' => $deployment->status->isFinished(),
            'succeeded' => $deployment->status->value === 'active' || $deployment->status->value === 'superseded',
            'commit' => $deployment->commit_sha === null ? null : substr($deployment->commit_sha, 0, 12),
            'commit_message' => $deployment->commit_message,
            'is_rollback' => $deployment->rolled_back_from_id !== null,
            'error' => $deployment->error,
            'created_at' => $deployment->created_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
            'url' => route('api.apps.deployments.show', ['app' => $app->slug, 'number' => $deployment->number]),
            ...($withLog ? ['log' => $deployment->log ?? ''] : []),
        ];
    }
}
