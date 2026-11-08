<?php

namespace App\Alerts;

use Illuminate\Support\Str;

/**
 * A backup, a database dump, a volume archive or the off-server copy failed. What failed and
 * the reason the panel already shows; never a secret.
 */
class BackupAlerts
{
    public function __construct(private readonly Alerter $alerter) {}

    public static function failed(string $what, string $reason): void
    {
        app(self::class)->send($what, $reason);
    }

    public function send(string $what, string $reason): void
    {
        $reason = Str::limit(trim($reason), 300, '');

        $this->alerter->send(new AlertMessage(
            AlertEvent::BackupFailed,
            "Backup failed: {$what}",
            $reason === '' ? [] : [$reason],
            route('settings.backups'),
            null,
            ['what' => $what, 'reason' => $reason],
        ));
    }
}
