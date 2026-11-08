<?php

namespace App\Jobs;

use App\Alerts\BackupAlerts;
use App\Audit\AuditLogger;
use App\Backups\BackupFailed;
use App\Backups\VolumeBackups;
use App\Models\App;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class BackupVolumeJob implements ShouldQueue
{
    use Queueable;

    /** The agent stops at one hour; this leaves room around it. */
    public int $timeout = 3900;

    public int $tries = 1;

    public function __construct(public int $appId, public string $volume) {}

    public static function pendingKey(string $slug, string $volume): string
    {
        return "commypanel.volume-backup.{$slug}.{$volume}";
    }

    public function handle(VolumeBackups $backups, AuditLogger $audit): void
    {
        $app = App::query()->find($this->appId);

        if ($app === null) {
            return;
        }

        $key = self::pendingKey($app->slug, $this->volume);

        try {
            $backup = $backups->create($app, $this->volume);
            Cache::forget($key);
            $audit->record('volume.backed_up', null, ['app' => $app->slug, 'volume' => $this->volume, 'file' => $backup['name']]);
        } catch (BackupFailed $e) {
            // Shown on the volumes tab until the next try.
            Cache::put($key, ['failed' => $e->getMessage()], now()->addDay());
            BackupAlerts::failed("volume {$this->volume} of {$app->name}", $e->getMessage());
        }
    }
}
