<?php

namespace App\Backups;

use App\Backups\Remote\RemoteBackups;
use PDO;
use Throwable;

/**
 * Backups of the panel's own data: its main database (apps, domains, users,
 * database servers and their encrypted secrets). Each backup is one compressed
 * SQLite file in <data dir>/backups/panel, readable only by the panel user.
 *
 * What a backup does not hold: the files of apps and databases, and the panel's
 * APP_KEY. Without the key, the encrypted values in a restored database
 * (environment variables, tokens, database passwords) cannot be read, so the key
 * has to be kept somewhere safe on its own.
 */
class PanelBackups
{
    private const NAME = '/^panel-\d{8}-\d{6}(-[a-z]+)?\.sqlite\.gz$/';

    public function __construct(private readonly DatabaseSnapshotter $snapshotter) {}

    public function directory(): string
    {
        return rtrim((string) config('commypanel.data_path'), '/').'/backups/panel';
    }

    /**
     * Take a backup now and drop the oldest ones beyond the number to keep.
     *
     * @return array{name: string, size: int, created: int}
     *
     * @throws BackupFailed
     */
    public function create(string $label = ''): array
    {
        $dir = $this->directory();

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new BackupFailed(__('The backup folder :dir could not be created.', ['dir' => $dir]));
        }

        $suffix = $label === '' ? '' : '-'.preg_replace('/[^a-z]/', '', strtolower($label));
        $name = 'panel-'.date('Ymd-His').$suffix.'.sqlite.gz';
        $temporary = $dir.'/.snapshot-'.bin2hex(random_bytes(4)).'.sqlite';
        $final = $dir.'/'.$name;

        try {
            $this->snapshotter->snapshot($temporary);
            $this->assertHealthy($temporary);
            $this->compress($temporary, $final);
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }

        @chmod($final, 0600);
        $this->prune((int) config('commypanel.backups.keep'));
        app(RemoteBackups::class)->queue('panel', $this->directory().'/'.$name);

        return $this->describe($name);
    }

    /**
     * @return list<array{name: string, size: int, created: int}>
     */
    public function all(): array
    {
        $files = glob($this->directory().'/panel-*.sqlite.gz') ?: [];
        $out = [];

        foreach ($files as $file) {
            if (preg_match(self::NAME, basename($file)) === 1) {
                $out[] = $this->describe(basename($file));
            }
        }

        usort($out, fn (array $a, array $b) => [$b['created'], $b['name']] <=> [$a['created'], $a['name']]);

        return $out;
    }

    /**
     * The full path of a backup by its name; nothing else can be named.
     *
     * @throws BackupFailed
     */
    public function path(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1 || ! is_file($this->directory().'/'.$name)) {
            throw new BackupFailed(__('There is no such backup.'));
        }

        return $this->directory().'/'.$name;
    }

    /**
     * @throws BackupFailed
     */
    public function delete(string $name): void
    {
        @unlink($this->path($name));
    }

    /**
     * Keep the newest backups and delete the rest; returns how many went. The
     * backup taken just before a restore is never counted out by age alone,
     * because it is one of the newest.
     */
    public function prune(int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $removed = 0;

        foreach (array_slice($this->all(), $keep) as $old) {
            if (@unlink($this->directory().'/'.$old['name'])) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Replace the live database with a backup. The panel's services must be
     * stopped first (the `commypanel restore` command does that); the current
     * database is backed up before it is overwritten.
     *
     * @throws BackupFailed
     */
    public function restore(string $file): void
    {
        $live = $this->snapshotter->livePath();

        if ($live === null) {
            throw new BackupFailed(__('The panel database is not a file, so there is nothing to restore into.'));
        }

        if (! is_file($file) || ! is_readable($file)) {
            throw new BackupFailed(__('The backup :file cannot be read.', ['file' => $file]));
        }

        $incoming = dirname($live).'/.restore-'.bin2hex(random_bytes(4)).'.sqlite';

        try {
            $this->decompress($file, $incoming);
            $this->assertHealthy($incoming);
            $this->assertIsPanelDatabase($incoming);

            // A way back if the wrong file was chosen.
            $this->create('before');

            if (! @rename($incoming, $live)) {
                throw new BackupFailed('The database file could not be replaced.');
            }

            // Leftovers of the old database's write-ahead log would corrupt the restored one.
            foreach (['-wal', '-shm'] as $extension) {
                @unlink($live.$extension);
            }

            @chmod($live, 0600);
        } finally {
            if (file_exists($incoming)) {
                @unlink($incoming);
            }
        }
    }

    /**
     * @return array{name: string, size: int, created: int}
     */
    private function describe(string $name): array
    {
        $path = $this->directory().'/'.$name;

        return ['name' => $name, 'size' => (int) filesize($path), 'created' => (int) filemtime($path)];
    }

    /**
     * @throws BackupFailed
     */
    private function assertHealthy(string $file): void
    {
        try {
            $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $pdo->query('PRAGMA integrity_check');
            $result = $statement === false ? 'unreadable' : (string) $statement->fetchColumn();
        } catch (Throwable $e) {
            throw new BackupFailed('The copy is not a readable database: '.$e->getMessage(), 0, $e);
        } finally {
            $pdo = null;
        }

        if ($result !== 'ok') {
            throw new BackupFailed("The copy failed its integrity check ({$result}).");
        }
    }

    /**
     * @throws BackupFailed
     */
    private function assertIsPanelDatabase(string $file): void
    {
        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'");
        $tables = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
        $pdo = null;

        foreach (['migrations', 'users', 'apps'] as $needed) {
            if (! in_array($needed, $tables, true)) {
                throw new BackupFailed("This does not look like a CommyPanel database (the {$needed} table is missing).");
            }
        }
    }

    /**
     * @throws BackupFailed
     */
    private function compress(string $from, string $to): void
    {
        $in = @fopen($from, 'rb');
        $out = @gzopen($to, 'wb6');

        if ($in === false || $out === false) {
            throw new BackupFailed('The backup file could not be written.');
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }

        fclose($in);
        gzclose($out);
    }

    /**
     * @throws BackupFailed
     */
    private function decompress(string $from, string $to): void
    {
        $in = @gzopen($from, 'rb');
        $out = @fopen($to, 'wb');

        if ($in === false || $out === false) {
            throw new BackupFailed('The backup could not be read.');
        }

        while (! gzeof($in)) {
            $chunk = gzread($in, 1 << 20);

            if ($chunk === false) {
                throw new BackupFailed('The backup is damaged.');
            }

            fwrite($out, $chunk);
        }

        gzclose($in);
        fclose($out);
    }
}
