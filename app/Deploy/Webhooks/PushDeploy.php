<?php

namespace App\Deploy\Webhooks;

use App\Audit\AuditLogger;
use App\Deploy\Deployer;
use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\App;

/**
 * What a verified push does to an app: deploy it, unless it is stopped, the push was to another
 * branch or a deployment is already waiting. Shared by the per-app webhook and the GitHub App's.
 */
class PushDeploy
{
    public function __construct(private readonly Deployer $deployer, private readonly AuditLogger $audit) {}

    /**
     * @return array{queued: bool, message: string}
     */
    public function handle(App $app, ?string $branch, bool $isPush, ?string $commit, ?string $ip): array
    {
        $skip = match (true) {
            ! $isPush => 'Not a push event.',
            $branch !== $app->git_branch => "Not a push to {$app->git_branch}.",
            in_array($app->load('latestDeployment')->status(), [AppStatus::Stopped, AppStatus::Deactivated], true) => 'The app is stopped; start it in CommyPanel first.',
            $app->deployments()->where('status', DeploymentStatus::Queued)->exists() => 'A deployment is already waiting to run.',
            default => null,
        };

        if ($skip !== null) {
            return ['queued' => false, 'message' => "Ignored: {$skip}"];
        }

        $deployment = $this->deployer->deploy($app);
        $this->audit->record('app.webhook_deploy', null, [
            'app' => $app->slug,
            'deployment' => $deployment->number,
            'commit' => $commit,
            'ip' => $ip,
        ], $app);

        return ['queued' => true, 'message' => "Deployment #{$deployment->number} queued."];
    }
}
