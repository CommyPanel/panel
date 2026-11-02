<?php

namespace App\Backups;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * VACUUM INTO: SQLite writes a complete, consistent copy while the panel keeps
 * working. A plain file copy of a live database (with its write-ahead log) can
 * be torn, so it is never used.
 */
class SqliteSnapshotter implements DatabaseSnapshotter
{
    public function snapshot(string $toPath): void
    {
        // The path is made by us, but quote it properly anyway.
        $quoted = "'".str_replace("'", "''", $toPath)."'";

        try {
            DB::connection('main')->statement("VACUUM INTO {$quoted}");
        } catch (Throwable $e) {
            throw new BackupFailed('The database could not be copied: '.$e->getMessage(), 0, $e);
        }
    }

    public function livePath(): ?string
    {
        $path = config('database.connections.main.database');

        return is_string($path) && $path !== ':memory:' && $path !== '' ? $path : null;
    }
}
