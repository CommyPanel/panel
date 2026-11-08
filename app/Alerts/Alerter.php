<?php

namespace App\Alerts;

use App\Jobs\SendAlertJob;
use App\Models\AlertTarget;
use App\Models\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only way to send an alert: finds the enabled targets that listen for the event and
 * queues one retrying job for each. Whatever goes wrong here is logged and nothing more: an
 * alert never affects what raised it.
 */
class Alerter
{
    public function __construct(private readonly AlertDelivery $delivery) {}

    public function send(AlertMessage $message): void
    {
        if ($message->event === null) {
            return;
        }

        try {
            if ($message->app !== null && App::query()->where('slug', $message->app)->where('alerts_enabled', false)->exists()) {
                return;
            }

            $key = $message->event->value;

            foreach (AlertTarget::query()->where('enabled', true)->orderBy('id')->get() as $target) {
                if ($target->listensTo($key)) {
                    SendAlertJob::dispatch($target->id, $message);
                }
            }
        } catch (Throwable $e) {
            Log::warning('An alert could not be queued.', ['event' => $message->event->value, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Sends a test message to one target now. Returns what went wrong, or null.
     */
    public function test(AlertTarget $target): ?string
    {
        $message = new AlertMessage(null, 'CommyPanel test message', ['If you can read this, alerts will arrive here.'], url('/'), data: []);

        try {
            $this->delivery->deliver($target, $message);
        } catch (AlertFailed $e) {
            $this->record($target, $e->getMessage());

            return $e->getMessage();
        }

        $this->record($target, null);

        return null;
    }

    public function record(AlertTarget $target, ?string $error): void
    {
        AlertTarget::query()->whereKey($target->id)->update($error === null
            ? ['last_ok_at' => now(), 'last_error' => null]
            : ['last_error' => mb_substr($error, 0, 300)]);
    }
}
