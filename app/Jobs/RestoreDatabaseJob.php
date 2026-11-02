<?php

namespace App\Jobs;

use App\Databases\RestoreDatabase;
use App\Models\ManagedDatabase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RestoreDatabaseJob implements ShouldQueue
{
    use Queueable;

    /** The agent stops a restore at 30 minutes; this leaves room around it. */
    public int $timeout = 2100;

    public int $tries = 1;

    public function __construct(public int $databaseId) {}

    public function handle(RestoreDatabase $restore): void
    {
        $database = ManagedDatabase::query()->with('server')->find($this->databaseId);

        if ($database !== null && $restore->isRestoring($database)) {
            $restore->run($database);
        }
    }
}
