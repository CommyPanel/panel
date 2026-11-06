<?php

namespace App\Console\Commands;

use App\Databases\RemoteAccess;
use Illuminate\Console\Command;

/** Every minute: close database access that has run out and open again what the agent lost. */
class TickRemoteAccessCommand extends Command
{
    protected $signature = 'commypanel:tick-remote-access';

    protected $description = 'Close expired remote database access and re-open what the agent lost';

    public function handle(RemoteAccess $access): int
    {
        $access->reconcile();

        return self::SUCCESS;
    }
}
