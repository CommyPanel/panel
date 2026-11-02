<?php

namespace App\Databases;

use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\RestoreDatabaseJob;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;

/**
 * Loads a dump into a NEW database on the same server, so nothing that exists is
 * overwritten: the app can be pointed at the restored copy once it looks right, and
 * the old database deleted after. The dump must be one the agent made, which the
 * agent checks itself.
 */
class RestoreDatabase
{
    public const RESTORING = 'restoring';

    public const FAILED = 'failed';

    public function __construct(
        private readonly HostDriver $host,
        private readonly ManageDatabases $databases,
        private readonly DatabaseBackups $backups,
    ) {}

    /**
     * Make the new database and queue the loading.
     *
     * @throws DatabaseException
     */
    public function start(DatabaseServer $server, string $backup, string $newName): ManagedDatabase
    {
        $this->backups->path($server, $backup);

        $database = $this->databases->create($server, $newName);
        $database->forceFill(['restore_status' => self::RESTORING, 'restore_from' => $backup, 'restore_error' => null])->save();

        RestoreDatabaseJob::dispatch($database->id);

        return $database;
    }

    /**
     * Load the dump. A failure leaves the (partly filled) database in place, marked
     * failed with the reason, for the person to look at or delete.
     */
    public function run(ManagedDatabase $database): void
    {
        $server = $database->server;

        try {
            $this->host->restoreDatabase($server->target(), $database->name, $database->username, $server->slug, (string) $database->restore_from);
        } catch (HostOperationFailed $e) {
            $database->forceFill(['restore_status' => self::FAILED, 'restore_error' => $e->getMessage()])->save();

            return;
        }

        $database->forceFill(['restore_status' => null, 'restore_from' => null, 'restore_error' => null])->save();
    }

    public function isRestoring(ManagedDatabase $database): bool
    {
        return $database->restore_status === self::RESTORING;
    }
}
