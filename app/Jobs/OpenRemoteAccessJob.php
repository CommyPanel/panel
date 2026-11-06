<?php

namespace App\Jobs;

use App\Databases\ProvisionDatabaseServer;
use App\Databases\RemoteAccess;
use App\Models\DatabaseServer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Finishes opening a database server to the internet: certificate, a restart with TLS if needed, then the port. */
class OpenRemoteAccessJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $serverId) {}

    public function handle(RemoteAccess $access, ProvisionDatabaseServer $provision): void
    {
        $server = DatabaseServer::query()->find($this->serverId);

        if ($server === null || $server->remote_status !== 'opening') {
            return;
        }

        $access->finishOpening($server, fn (DatabaseServer $s) => $provision($s));
    }
}
