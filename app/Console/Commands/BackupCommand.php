<?php

namespace App\Console\Commands;

use App\Backups\BackupFailed;
use App\Backups\PanelBackups;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'commypanel:backup {--keep= : How many backups to keep (default: BACKUPS_KEEP)}';

    protected $description = "Back up the panel's own database";

    public function handle(PanelBackups $backups): int
    {
        try {
            $backup = $backups->create();
        } catch (BackupFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('keep') !== null) {
            $backups->prune((int) $this->option('keep'));
        }

        $this->components->info("Backup {$backup['name']} written to {$backups->directory()}.");

        return self::SUCCESS;
    }
}
