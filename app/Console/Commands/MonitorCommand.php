<?php

namespace App\Console\Commands;

use App\Alerts\Monitor;
use Illuminate\Console\Command;
use Throwable;

class MonitorCommand extends Command
{
    protected $signature = 'commypanel:monitor';

    protected $description = 'Look at apps, database servers and the disk, and send alerts when something changed';

    public function handle(Monitor $monitor): int
    {
        // Each part on its own: a failure in one must not hide the others.
        foreach (['apps', 'databases', 'disk'] as $part) {
            try {
                $monitor->{$part}();
            } catch (Throwable $e) {
                report($e);
                $this->components->error("Could not check {$part}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
