<?php

namespace App\Console\Commands;

use App\Backups\BackupFailed;
use App\Backups\PanelBackups;
use Illuminate\Console\Command;

class RestoreCommand extends Command
{
    protected $signature = 'commypanel:restore {file : A backup file, or the name of one in the backup folder} {--force : Confirm that the panel\'s services are stopped}';

    protected $description = "Replace the panel's database with a backup (run it through `commypanel restore`, which stops the services first)";

    public function handle(PanelBackups $backups): int
    {
        if (! $this->option('force')) {
            $this->components->error('Restoring overwrites the panel database and needs the panel\'s services stopped. Use: sudo commypanel restore FILE');

            return self::FAILURE;
        }

        $file = (string) $this->argument('file');

        // A bare name means a backup in the backup folder.
        if (! str_contains($file, '/')) {
            try {
                $file = $backups->path($file);
            } catch (BackupFailed $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $backups->restore($file);
        } catch (BackupFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The database was restored. The database as it was before is kept as a backup ending in -before.');

        return self::SUCCESS;
    }
}
