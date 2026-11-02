<?php

namespace App\Jobs;

use App\Databases\ProvisionDatabaseServer;
use App\Models\DatabaseServer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionDatabaseServerJob implements ShouldQueue
{
    use Queueable;

    /** A first download and start can take several minutes. */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $serverId) {}

    public function handle(ProvisionDatabaseServer $provision): void
    {
        $server = DatabaseServer::query()->find($this->serverId);

        if ($server !== null) {
            $provision($server);
        }
    }
}
