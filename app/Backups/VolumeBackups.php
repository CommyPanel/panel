<?php

namespace App\Backups;

use App\Backups\Remote\RemoteBackups;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;

/**
 * Archives of an app's volumes: a gzipped tar of a volume's folders and plain
 * files, kept on this server in <data dir>/backups/volumes/<app>/, readable by
 * the panel user only. Only the newest few of each volume are kept.
 *
 * To put one back, `RestoreVolumeJob` stops the app, has the agent unpack the archive over
 * the volume's folder, and starts the app again.
 */
class VolumeBackups
{
    private const NAME = '/^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?-\d{8}-\d{6}\.tar\.gz$/';

    public function __construct(private readonly HostDriver $host) {}

    public function directory(App $app): string
    {
        return rtrim((string) config('commypanel.data_path'), '/').'/backups/volumes/'.$app->slug;
    }

    /**
     * @return array{name: string, volume: string, size: int, created: int}
     *
     * @throws BackupFailed
     */
    public function create(App $app, string $volume, bool $prune = true): array
    {
        $dir = $this->directory($app);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new BackupFailed(__('The backup folder :dir could not be created.', ['dir' => $dir]));
        }

        $stamp = time();

        // Never overwrite a backup made in the same second (a safety copy right after a dump).
        while (is_file($dir.'/'.$volume.'-'.date('Ymd-His', $stamp).'.tar.gz')) {
            $stamp++;
        }

        $name = $volume.'-'.date('Ymd-His', $stamp).'.tar.gz';

        try {
            $this->host->backupVolume($app->slug, $volume, $dir.'/'.$name);
        } catch (HostOperationFailed $e) {
            throw new BackupFailed(__('Could not back up :volume: :error', ['volume' => $volume, 'error' => $e->getMessage()]), 0, $e);
        }

        @chmod($dir.'/'.$name, 0600);
        // A safety archive taken before a restore must not push out the one about to be unpacked.
        if ($prune) {
            $this->prune($app, $volume, (int) config('commypanel.backups.keep_volumes'));
        }

        app(RemoteBackups::class)->queue('volumes/'.$app->slug, $dir.'/'.$name);

        return $this->describe($app, $name);
    }

    /**
     * @return list<array{name: string, volume: string, size: int, created: int}>
     */
    public function all(App $app): array
    {
        $out = [];

        foreach (glob($this->directory($app).'/*.tar.gz') ?: [] as $file) {
            if (preg_match(self::NAME, basename($file)) === 1) {
                $out[] = $this->describe($app, basename($file));
            }
        }

        usort($out, fn (array $a, array $b) => [$b['created'], $b['name']] <=> [$a['created'], $a['name']]);

        return $out;
    }

    /**
     * @throws BackupFailed
     */
    public function path(App $app, string $name): string
    {
        $path = $this->directory($app).'/'.$name;

        if (preg_match(self::NAME, $name) !== 1 || ! is_file($path)) {
            throw new BackupFailed(__('There is no such backup.'));
        }

        return $path;
    }

    /**
     * @throws BackupFailed
     */
    public function delete(App $app, string $name): void
    {
        @unlink($this->path($app, $name));
    }

    public function prune(App $app, string $volume, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $own = array_values(array_filter($this->all($app), fn (array $b) => $b['volume'] === $volume));
        $removed = 0;

        foreach (array_slice($own, $keep) as $old) {
            if (@unlink($this->directory($app).'/'.$old['name'])) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Remove every archive of an app, when the app is deleted.
     */
    public function deleteAll(App $app): void
    {
        foreach (glob($this->directory($app).'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory($app));
    }

    /**
     * @return array{name: string, volume: string, size: int, created: int}
     */
    private function describe(App $app, string $name): array
    {
        $path = $this->directory($app).'/'.$name;

        return [
            'name' => $name,
            'volume' => (string) preg_replace('/-\d{8}-\d{6}\.tar\.gz$/', '', $name),
            'size' => (int) filesize($path),
            'created' => (int) filemtime($path),
        ];
    }
}
