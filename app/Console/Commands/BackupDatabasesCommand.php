<?php

namespace App\Console\Commands;

use App\Alerts\BackupAlerts;
use App\Databases\DatabaseBackups;
use App\Databases\DatabaseException;
use App\Models\ManagedDatabase;
use Illuminate\Console\Command;

class BackupDatabasesCommand extends Command
{
    protected $signature = 'commypanel:backup-databases';

    protected $description = 'Dump every database on every running database server';

    public function handle(DatabaseBackups $backups): int
    {
        $failed = 0;
        $made = 0;

        foreach (ManagedDatabase::query()->with('server')->orderBy('id')->get() as $database) {
            if (! $database->server->isRunning()) {
                continue;
            }

            try {
                $backups->create($database);
                $made++;
            } catch (DatabaseException $e) {
                $failed++;
                BackupAlerts::failed("database {$database->name} on {$database->server->name}", $e->getMessage());
                $this->components->error($e->getMessage());
            }
        }

        $this->components->info("{$made} database backup(s) made".($failed > 0 ? ", {$failed} failed" : '').'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
