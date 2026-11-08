<?php

namespace App\Databases;

use App\Backups\Remote\RemoteBackups;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;

/**
 * Dumps of databases: the engine's own backup tool writes plain SQL that
 * recreates the data, compressed to one file. They are kept on this server in
 * <data dir>/backups/databases/<server>/, readable by the panel user only, and
 * the newest few of each database are kept.
 */
class DatabaseBackups
{
    private const NAME = '/^[a-z][a-z0-9_]{0,30}-\d{8}-\d{6}\.sql\.gz$/';

    public function __construct(private readonly HostDriver $host) {}

    public function directory(DatabaseServer $server): string
    {
        return rtrim((string) config('commypanel.data_path'), '/').'/backups/databases/'.$server->slug;
    }

    /**
     * @return array{name: string, database: string, size: int, created: int}
     *
     * @throws DatabaseException
     */
    public function create(ManagedDatabase $database): array
    {
        $server = $database->server;

        if (! $server->isRunning()) {
            throw new DatabaseException(__('The server is not running. Start it first.'));
        }

        $dir = $this->directory($server);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new DatabaseException(__('The backup folder :dir could not be created.', ['dir' => $dir]));
        }

        $name = $database->name.'-'.date('Ymd-His').'.sql.gz';

        try {
            $this->host->dumpDatabase($server->target(), $database->name, $dir.'/'.$name);
        } catch (HostOperationFailed $e) {
            throw new DatabaseException(__('Could not back up :name: :error', ['name' => $database->name, 'error' => $e->getMessage()]), 0, $e);
        }

        @chmod($dir.'/'.$name, 0600);
        $this->prune($server, $database->name, (int) config('commypanel.backups.keep_databases'));
        app(RemoteBackups::class)->queue('databases/'.$server->slug, $dir.'/'.$name);

        return $this->describe($server, $name);
    }

    /**
     * @return list<array{name: string, database: string, size: int, created: int}>
     */
    public function all(DatabaseServer $server): array
    {
        $out = [];

        foreach (glob($this->directory($server).'/*.sql.gz') ?: [] as $file) {
            if (preg_match(self::NAME, basename($file)) === 1) {
                $out[] = $this->describe($server, basename($file));
            }
        }

        usort($out, fn (array $a, array $b) => [$b['created'], $b['name']] <=> [$a['created'], $a['name']]);

        return $out;
    }

    /**
     * @throws DatabaseException
     */
    public function path(DatabaseServer $server, string $name): string
    {
        $path = $this->directory($server).'/'.$name;

        if (preg_match(self::NAME, $name) !== 1 || ! is_file($path)) {
            throw new DatabaseException(__('There is no such backup.'));
        }

        return $path;
    }

    /**
     * @throws DatabaseException
     */
    public function delete(DatabaseServer $server, string $name): void
    {
        @unlink($this->path($server, $name));
    }

    public function prune(DatabaseServer $server, string $database, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $own = array_values(array_filter($this->all($server), fn (array $b) => $b['database'] === $database));
        $removed = 0;

        foreach (array_slice($own, $keep) as $old) {
            if (@unlink($this->directory($server).'/'.$old['name'])) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Remove every dump of a server, when the server is deleted.
     */
    public function deleteAll(DatabaseServer $server): void
    {
        foreach (glob($this->directory($server).'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory($server));
    }

    /**
     * @return array{name: string, database: string, size: int, created: int}
     */
    private function describe(DatabaseServer $server, string $name): array
    {
        $path = $this->directory($server).'/'.$name;

        return [
            'name' => $name,
            'database' => (string) preg_replace('/-\d{8}-\d{6}\.sql\.gz$/', '', $name),
            'size' => (int) filesize($path),
            'created' => (int) filemtime($path),
        ];
    }
}
