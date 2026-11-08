<?php

namespace App\Alerts;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells the targets how a deployment ended. The message holds the app, the deployment, the
 * commit and the reason of a failure, with a link to the panel: never the log, environment
 * variables or any secret. Text that came from a repo (the commit message) is made harmless
 * by AlertDelivery.
 */
class DeployAlerts
{
    public function __construct(private readonly Alerter $alerter) {}

    /**
     * Called wherever a deployment reaches its end. Never throws: a message that cannot be
     * made or queued must not touch the deployment.
     */
    public static function dispatchFor(Deployment $deployment): void
    {
        try {
            app(self::class)->deployment($deployment->fresh() ?? $deployment);
        } catch (Throwable $e) {
            Log::warning('A deployment alert could not be made.', ['error' => $e->getMessage()]);
        }
    }

    public function deployment(Deployment $deployment): void
    {
        $event = match ($deployment->status) {
            DeploymentStatus::Active, DeploymentStatus::Superseded => AlertEvent::DeploymentSucceeded,
            DeploymentStatus::RolledBack => AlertEvent::DeploymentRolledBack,
            DeploymentStatus::Failed => AlertEvent::DeploymentFailed,
            default => null,
        };

        if ($event === null) {
            return;
        }

        $deployment->loadMissing('app');
        $app = $deployment->app;
        $ok = $event === AlertEvent::DeploymentSucceeded;

        $title = match ($event) {
            AlertEvent::DeploymentSucceeded => "Deployed {$app->name} (#{$deployment->number})",
            AlertEvent::DeploymentRolledBack => "{$app->name} (#{$deployment->number}) was rolled back automatically",
            default => "Deployment of {$app->name} (#{$deployment->number}) failed",
        };

        $lines = [];

        if ($deployment->commit_sha !== null) {
            $lines[] = 'Commit '.substr($deployment->commit_sha, 0, 12).($deployment->commit_message === null ? '' : ': '.Str::limit(strtok($deployment->commit_message, "\n") ?: '', 120));
        }

        if (! $ok && $deployment->error !== null) {
            $lines[] = Str::limit($deployment->error, 300);
        }

        $link = route('apps.show', ['app' => $app->slug]);

        $this->alerter->send(new AlertMessage($event, $title, $lines, $link, $app->slug, [
            'deployment' => $deployment->number,
            'succeeded' => $ok,
            'commit' => $deployment->commit_sha === null ? null : substr($deployment->commit_sha, 0, 12),
            'error' => $ok ? null : Str::limit((string) $deployment->error, 300),
        ]));
    }
}
