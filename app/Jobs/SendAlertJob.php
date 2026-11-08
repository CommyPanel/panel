<?php

namespace App\Jobs;

use App\Alerts\AlertDelivery;
use App\Alerts\Alerter;
use App\Alerts\AlertFailed;
use App\Alerts\AlertMessage;
use App\Models\AlertTarget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one message to one target. A failure to deliver is only noted on the target
 * (shown on the settings page); it never touches what raised the alert.
 */
class SendAlertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $targetId, public readonly AlertMessage $message) {}

    public function handle(AlertDelivery $delivery, Alerter $alerter): void
    {
        $target = AlertTarget::query()->find($this->targetId);

        if ($target === null || ! $target->enabled) {
            return;
        }

        try {
            $delivery->deliver($target, $this->message);
        } catch (AlertFailed $e) {
            $alerter->record($target, $e->getMessage());

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 120);
            }

            return;
        }

        $alerter->record($target, null);
    }
}
