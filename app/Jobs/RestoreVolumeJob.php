<?php

namespace App\Jobs;

use App\Audit\AuditLogger;
use App\Backups\BackupFailed;
use App\Backups\VolumeBackups;
use App\Deploy\ControlApp;
use App\Deploy\DeploymentFailed;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Put an archive back over a volume: a safety archive of what is there, stop the app,
 * unpack, start the app again (also when unpacking failed). The outcome is left in the
 * cache for the volumes tab: true while running, then `done` or `failed`.
 */
class RestoreVolumeJob implements ShouldQueue
{
    use Queueable;

    /** The agent stops at one hour for the archive and again for unpacking; this leaves room around both. */
    public int $timeout = 8000;

    public int $tries = 1;

    public function __construct(public int $appId, public string $volume, public string $archive, public ?int $userId = null) {}

    public static function stateKey(string $slug, string $volume): string
    {
        return "commypanel.volume-restore.{$slug}.{$volume}";
    }

    public function handle(VolumeBackups $backups, ControlApp $control, HostDriver $host, AuditLogger $audit): void
    {
        $app = App::query()->find($this->appId);

        if ($app === null) {
            return;
        }

        $key = self::stateKey($app->slug, $this->volume);
        $context = ['app' => $app->slug, 'volume' => $this->volume, 'file' => $this->archive];
        $user = $this->userId === null ? null : User::query()->find($this->userId);

        $fail = function (string $message, array $extra = [], ?string $code = null) use ($key, $audit, $context) {
            Cache::put($key, ['failed' => $message] + ($code === null ? [] : ['code' => $code]), now()->addDay());
            $audit->record('volume.restore_failed', null, $context + $extra);
        };

        if ($app->isDeploying()) {
            $fail('Nothing was changed: a deployment is running. Try again when it is done.', ['changed' => false]);

            return;
        }

        $wasRunning = $app->active_deployment_id !== null && $app->stopped_at === null;

        try {
            $safety = $backups->create($app, $this->volume, prune: false)['name'];
        } catch (BackupFailed $e) {
            $fail('Nothing was changed: a safety archive of the volume could not be made first. '.$e->getMessage(), ['changed' => false]);

            return;
        }

        if ($wasRunning) {
            try {
                $control->stop($app, $user);
            } catch (DeploymentFailed $e) {
                $fail("Nothing was changed: the app could not be stopped. {$e->getMessage()}", ['changed' => false, 'safety' => $safety]);

                return;
            }
        }

        $error = null;
        $code = null;
        $result = null;

        try {
            $result = $host->restoreVolume($app->slug, $this->volume, $this->archive);
        } catch (HostOperationFailed $e) {
            $error = trim((string) preg_replace('/^Agent error:\s*/', '', $e->getMessage()));
            $code = str_contains($error, 'not made by CommyPanel') ? 'unrecorded' : null;
        }

        $restarted = ! $wasRunning;
        $startError = null;

        if ($wasRunning) {
            try {
                $control->start($app->fresh() ?? $app, $user);
                $restarted = true;
            } catch (DeploymentFailed $e) {
                $startError = $e->getMessage();
            }
        }

        $appNote = match (true) {
            ! $wasRunning => '',
            $restarted => ' The app was started again.',
            default => " The app could NOT be started again: {$startError} Start it from the app's page.",
        };

        if ($error !== null) {
            // These refusals come before the agent touches any file.
            $untouched = Str::contains($error, ['not made by CommyPanel', 'no such backup', 'does not exist', 'stop the app first', 'free space', 'not enough']);
            $what = $untouched
                ? "Restoring failed, and the volume was not changed: {$error}."
                : "Restoring failed: {$error}. The volume may hold a mix of old and new files; the safety archive {$safety} has what it held before.";
            $fail($what.$appNote, ['changed' => ! $untouched, 'safety' => $safety, 'restarted' => $restarted], $code);

            return;
        }

        Cache::put($key, ['done' => "Restored {$this->archive} ({$result['files']} files). What the volume held before is in {$safety}.{$appNote}", 'at' => time()], now()->addDay());

        if (! $restarted) {
            $audit->record('volume.restore_failed', null, $context + ['changed' => true, 'safety' => $safety, 'restarted' => false]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $app = App::query()->find($this->appId);

        if ($app !== null) {
            Cache::put(self::stateKey($app->slug, $this->volume), ['failed' => 'The restore stopped unexpectedly. Check whether the app is running, and look at the safety archive in the list of backups.'], now()->addDay());
        }
    }
}
