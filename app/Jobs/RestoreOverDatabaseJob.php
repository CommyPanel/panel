<?php

namespace App\Jobs;

use App\Databases\RestoreDatabase;
use App\Models\ManagedDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RestoreOverDatabaseJob implements ShouldQueue
{
    use Queueable;

    /** A safety dump, the restore and maybe a put-back, each stopped by the agent at 30 minutes. */
    public int $timeout = 6000;

    public int $tries = 1;

    public function __construct(public int $databaseId) {}

    public function handle(RestoreDatabase $restore): void
    {
        $database = ManagedDatabase::query()->with('server')->find($this->databaseId);

        if ($database !== null && $restore->isRestoring($database)) {
            $restore->runOver($database);
        }
    }

    public function failed(\Throwable $e): void
    {
        ManagedDatabase::query()->whereKey($this->databaseId)->where('restore_status', RestoreDatabase::RESTORING)
            ->update(['restore_status' => RestoreDatabase::FAILED, 'restore_error' => 'The restore stopped unexpectedly. The database may be damaged; a safety copy of it was taken first and is in the list of backups.']);
    }
}
