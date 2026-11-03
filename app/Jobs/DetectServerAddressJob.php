<?php

namespace App\Jobs;

use App\Dns\ServerAddress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finds the server's public address in the background, so no page waits for it.
 */
class DetectServerAddressJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function handle(ServerAddress $server): void
    {
        $server->runDetection();
    }
}
