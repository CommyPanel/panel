<?php

namespace App\Jobs;

use App\Alerts\AlertFailed;
use App\Alerts\AlertSettings;
use App\Alerts\DeployAlerts;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the configured chat how a deployment ended. A failure to deliver never touches the
 * deployment; it is only noted on the settings page.
 */
class SendDeployAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $deploymentId) {}

    /**
     * Queue a message for a finished deployment, only when messages are switched on.
     */
    public static function dispatchFor(Deployment $deployment): void
    {
        if (app(AlertSettings::class)->enabled()) {
            self::dispatch($deployment->id);
        }
    }

    public function handle(AlertSettings $settings, DeployAlerts $alerts): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);

        if ($deployment === null || ! $deployment->status->isFinished()) {
            return;
        }

        if (! $settings->wants(in_array($deployment->status->value, ['active', 'superseded'], true))) {
            return;
        }

        try {
            $alerts->deployment($deployment);
        } catch (AlertFailed $e) {
            $settings->recordFailure($e->getMessage());

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);
            }

            return;
        }

        $settings->recordSuccess();
    }
}
