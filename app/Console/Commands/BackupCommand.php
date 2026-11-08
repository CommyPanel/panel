<?php

namespace App\Console\Commands;

use App\Alerts\BackupAlerts;
use App\Backups\BackupFailed;
use App\Backups\PanelBackups;
use App\Host\Data\VolumeUsage;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\BackupVolumeJob;
use App\Models\App;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BackupCommand extends Command
{
    /** When the scheduler runs this command (routes/console.php), shown next to the nightly volume switch. */
    public const AT = '03:30';

    protected $signature = 'commypanel:backup {--keep= : How many backups to keep (default: BACKUPS_KEEP)}';

    protected $description = "Back up the panel's own database and the volumes that have the nightly archive switched on";

    public function handle(PanelBackups $backups, HostDriver $host): int
    {
        $this->queueVolumes($host);

        try {
            $backup = $backups->create();
        } catch (BackupFailed $e) {
            BackupAlerts::failed('the panel database', $e->getMessage());
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('keep') !== null) {
            $backups->prune((int) $this->option('keep'));
        }

        $this->components->info("Backup {$backup['name']} written to {$backups->directory()}.");

        return self::SUCCESS;
    }

    /**
     * Queue an archive of every volume with the nightly switch on. They run on the queue, one
     * after another, because a big volume takes long; a failure raises the backup alert there.
     */
    private function queueVolumes(HostDriver $host): void
    {
        foreach (App::query()->whereHas('volumes', fn ($q) => $q->where('nightly_backup', true))->orderBy('id')->get() as $app) {
            try {
                $onDisk = collect($host->listVolumes($app->slug))->map(fn (VolumeUsage $v) => $v->name)->all();
            } catch (HostOperationFailed) {
                $onDisk = null; // cannot tell: let the archive itself report the problem
            }

            foreach ($app->volumes()->where('nightly_backup', true)->orderBy('id')->get() as $volume) {
                // A volume has no folder until a container using it has started: nothing to archive yet.
                if ($onDisk !== null && ! in_array($volume->name, $onDisk, true)) {
                    continue;
                }

                $key = BackupVolumeJob::pendingKey($app->slug, $volume->name);

                if (Cache::get($key) === true) {
                    continue;
                }

                Cache::put($key, true, now()->addHours(2));
                BackupVolumeJob::dispatch($app->id, $volume->name);
            }
        }
    }
}
