<?php

namespace App\Backups;

/**
 * Writes a consistent copy of the panel's main database to a file.
 */
interface DatabaseSnapshotter
{
    /**
     * @throws BackupFailed
     */
    public function snapshot(string $toPath): void;

    /**
     * Where the live database file is, or null when it is not a file (a test database).
     */
    public function livePath(): ?string;
}
