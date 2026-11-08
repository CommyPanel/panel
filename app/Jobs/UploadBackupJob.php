<?php

namespace App\Jobs;

use App\Alerts\BackupAlerts;
use App\Backups\Remote\RemoteBackupFailed;
use App\Backups\Remote\RemoteBackups;
use App\Backups\Remote\RemoteSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class UploadBackupJob implements ShouldQueue
{
    use Queueable;

    /** A large archive over a slow link takes long; the client stops at one hour. */
    public int $timeout = 3900;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [120, 600];

    public function __construct(public string $series, public string $file) {}

    /**
     * @throws RemoteBackupFailed
     */
    public function handle(RemoteBackups $remote): void
    {
        $remote->upload($this->series, $this->file);
    }

    public function failed(Throwable $e): void
    {
        $reason = $e instanceof RemoteBackupFailed ? $e->getMessage() : 'The copy failed.';
        app(RemoteSettings::class)->recordFailure($reason);
        BackupAlerts::failed('the copy to the off-server storage', $reason);
    }
}
