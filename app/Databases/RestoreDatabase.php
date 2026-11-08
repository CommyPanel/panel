<?php

namespace App\Databases;

use App\Audit\AuditLogger;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\RestoreDatabaseJob;
use App\Jobs\RestoreOverDatabaseJob;
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
        private readonly AuditLogger $audit,
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

    /**
     * Queue loading a dump over an EXISTING database (everything in it is replaced).
     * Refuses unless the server runs, the database is on it, the dump exists and no
     * restore is running on the database.
     *
     * @throws DatabaseException
     */
    public function startOver(DatabaseServer $server, ManagedDatabase $database, string $backup): void
    {
        if (! $server->isRunning()) {
            throw new DatabaseException(__('The server is not running. Start it first.'));
        }

        if ($database->database_server_id !== $server->id) {
            throw new DatabaseException(__('That database is not on this server.'));
        }

        if ($this->isRestoring($database)) {
            throw new DatabaseException(__('A backup is already being loaded into this database. Wait for it to finish.'));
        }

        $this->backups->path($server, $backup);

        $database->forceFill(['restore_status' => self::RESTORING, 'restore_from' => $backup, 'restore_error' => null, 'restore_notice' => null])->save();

        RestoreOverDatabaseJob::dispatch($database->id);
    }

    /**
     * Replace the database with the dump. A safety dump of what is there now is taken
     * first and stays in the dump list; if the agent fails, that copy is put back. The
     * outcome is left on the database: nothing at all on success, a notice when the
     * database is as it was, and the failed status when it may be damaged.
     */
    public function runOver(ManagedDatabase $database): void
    {
        $server = $database->server;
        $file = (string) $database->restore_from;
        $context = ['server' => $server->slug, 'database' => $database->name, 'file' => $file];

        try {
            $safety = $this->backups->create($database, prune: false)['name'];
        } catch (DatabaseException $e) {
            $this->reverted($database, 'Nothing was changed: a safety copy of the database could not be made first. '.$e->getMessage());
            $this->audit->record('database.restore_replace_failed', null, $context + ['changed' => false]);

            return;
        }

        try {
            $this->host->restoreDatabase($server->target(), $database->name, $database->username, $server->slug, $file, 'replace');
        } catch (HostOperationFailed $e) {
            $reason = $this->plain($e->getMessage());

            // These refusals come before anything is touched.
            if (str_contains($reason, 'so it is not restored') || str_contains($reason, 'nothing to replace')) {
                $this->reverted($database, "Nothing was changed: {$reason}");
                $this->audit->record('database.restore_replace_failed', null, $context + ['changed' => false, 'safety' => $safety]);

                return;
            }

            $this->putBack($database, $file, $safety, $reason, $context);

            return;
        }

        $database->forceFill(['restore_status' => null, 'restore_from' => null, 'restore_error' => null, 'restore_notice' => null])->save();
    }

    /**
     * @param  array<string, string>  $context
     */
    private function putBack(ManagedDatabase $database, string $file, string $safety, string $reason, array $context): void
    {
        $server = $database->server;

        try {
            $this->host->restoreDatabase($server->target(), $database->name, $database->username, $server->slug, $safety, 'replace');
        } catch (HostOperationFailed $e) {
            $database->forceFill([
                'restore_status' => self::FAILED,
                'restore_error' => "Loading {$file} failed ({$reason}), and putting the database back from the safety copy {$safety} failed too ({$this->plain($e->getMessage())}). The database may be empty. The safety copy is still in the list of backups: restore it over this database, or into a new one.",
            ])->save();
            $this->audit->record('database.restore_replace_failed', null, $context + ['changed' => true, 'put_back' => false, 'safety' => $safety]);

            return;
        }

        $this->reverted($database, "Loading {$file} failed ({$reason}). The database was put back as it was before, from the safety copy {$safety}, which stays in the list of backups.");
        $this->audit->record('database.restore_replace_failed', null, $context + ['changed' => true, 'put_back' => true, 'safety' => $safety]);
    }

    private function reverted(ManagedDatabase $database, string $notice): void
    {
        $database->forceFill(['restore_status' => null, 'restore_from' => null, 'restore_error' => null, 'restore_notice' => $notice])->save();
    }

    private function plain(string $message): string
    {
        return trim((string) preg_replace('/^Agent error:\s*/', '', $message));
    }

    public function isRestoring(ManagedDatabase $database): bool
    {
        return $database->restore_status === self::RESTORING;
    }
}
