<?php

namespace App\Backups\Remote;

use App\Jobs\UploadBackupJob;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Copies each backup to the S3-compatible storage the person set up, so that it
 * survives the loss of this server's disk, and keeps only the newest few copies of
 * each series there. Nothing happens until it is switched on in the settings.
 *
 * A series is a folder of related files: `panel`, `databases/<server>`, `volumes/<app>`.
 */
class RemoteBackups
{
    /** The name of a backup file without its time stamp (and the label of a panel backup). */
    private const STAMP = '/-\d{8}-\d{6}(-[a-z]+)?\.(sqlite|sql|tar)\.gz$/';

    /** How many objects the list shows. */
    public const LIST_LIMIT = 500;

    /** What a path below the prefix may look like: `panel/<file>`, `databases/<server>/<file>`, `volumes/<app>/<file>`. */
    private const PANEL_PATH = '#^panel/panel-\d{8}-\d{6}(?:-[a-z]+)?\.sqlite\.gz$#';

    private const DATABASE_PATH = '#^databases/([a-z0-9][a-z0-9-]{0,40})/[a-z][a-z0-9_]{0,30}-\d{8}-\d{6}\.sql\.gz$#';

    private const VOLUME_PATH = '#^volumes/([a-z0-9][a-z0-9-]{0,40})/[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?-\d{8}-\d{6}\.tar\.gz$#';

    public function __construct(private readonly RemoteSettings $settings) {}

    /**
     * The copies on the storage, grouped by kind (the panel's own, the dumps of each database
     * server, the archives of each app), newest first. Anything that is not named like one of
     * our backups is left out.
     *
     * @return array{groups: list<array{kind: string, label: string|null, files: list<array{path: string, name: string, size: int, modified: int}>}>, truncated: bool}
     *
     * @throws RemoteBackupFailed
     */
    public function files(): array
    {
        $client = $this->settings->client();

        if ($client === null || ! $this->settings->enabled()) {
            throw new RemoteBackupFailed('The copy to off-server storage is not switched on.');
        }

        $base = $this->settings->prefix() === '' ? '' : $this->settings->prefix().'/';
        [$objects, $truncated] = $client->listDetailed($base, self::LIST_LIMIT);
        $groups = [];

        foreach ($objects as $object) {
            $path = substr($object['key'], strlen($base));

            if (! str_starts_with($object['key'], $base) || ($parsed = $this->parse($path)) === null) {
                continue;
            }

            $id = $parsed['kind'].'/'.$parsed['label'];
            $groups[$id]['kind'] = $parsed['kind'];
            $groups[$id]['label'] = $parsed['label'] === '' ? null : $parsed['label'];
            $groups[$id]['files'][] = ['path' => $path, 'name' => basename($path), 'size' => $object['size'], 'modified' => $object['time']];
        }

        $order = ['panel' => 0, 'database' => 1, 'volume' => 2];
        uasort($groups, fn (array $a, array $b) => [$order[$a['kind']], $a['label']] <=> [$order[$b['kind']], $b['label']]);

        return ['groups' => array_values($groups), 'truncated' => $truncated];
    }

    /**
     * Open one copy for reading, by its path below the prefix. Only names like our backups are
     * accepted, so no other object of the bucket can be fetched through the panel.
     *
     * @return array{size: int, stream: StreamInterface}
     *
     * @throws RemoteBackupFailed
     */
    public function open(string $path): array
    {
        $client = $this->settings->client();

        if ($client === null || ! $this->settings->enabled()) {
            throw new RemoteBackupFailed('The copy to off-server storage is not switched on.');
        }

        if ($this->parse($path) === null) {
            throw new RemoteBackupFailed('That is not one of the backups.');
        }

        return $client->open($this->key(dirname($path), basename($path)));
    }

    /**
     * @return array{kind: string, label: string}|null
     */
    private function parse(string $path): ?array
    {
        if (preg_match(self::PANEL_PATH, $path) === 1) {
            return ['kind' => 'panel', 'label' => ''];
        }

        if (preg_match(self::DATABASE_PATH, $path, $m) === 1) {
            return ['kind' => 'database', 'label' => $m[1]];
        }

        if (preg_match(self::VOLUME_PATH, $path, $m) === 1) {
            return ['kind' => 'volume', 'label' => $m[1]];
        }

        return null;
    }

    /**
     * Queue the copy of a backup that was just made. A no-op while the copy is off.
     */
    public function queue(string $series, string $file): void
    {
        // The backup is already made and must stay a success, whatever state the
        // settings are in (a database being restored may not have them yet).
        try {
            if ($this->settings->enabled()) {
                UploadBackupJob::dispatch($series, $file);
            }
        } catch (Throwable $e) {
            Log::warning('Could not queue the copy of a backup.', ['file' => basename($file), 'error' => $e->getMessage()]);
        }
    }

    /**
     * @throws RemoteBackupFailed
     */
    public function upload(string $series, string $file): void
    {
        $client = $this->settings->client();

        if ($client === null || ! $this->settings->enabled()) {
            return;
        }

        if (! is_file($file)) {
            return; // deleted or pruned since it was queued
        }

        try {
            $client->put($this->key($series, basename($file)), $file);
            $this->prune($client, $series);
            $this->settings->recordSuccess();
        } catch (RemoteBackupFailed $e) {
            $this->settings->recordFailure($e->getMessage());

            throw $e;
        }
    }

    /**
     * Delete the copies beyond the newest few of each kind of file in a series.
     * Only files named like our backups are ever touched.
     *
     * @throws RemoteBackupFailed
     */
    private function prune(S3Client $client, string $series): void
    {
        $objects = $client->list($this->key($series, ''));
        $groups = [];

        foreach ($objects as $key => $time) {
            $name = basename($key);

            if (preg_match(self::STAMP, $name) === 1) {
                $groups[(string) preg_replace(self::STAMP, '', $name)][$key] = $time;
            }
        }

        foreach ($groups as $group) {
            arsort($group);

            foreach (array_slice(array_keys($group), $this->settings->keep()) as $old) {
                $client->delete($old);
            }
        }
    }

    private function key(string $series, string $name): string
    {
        return ltrim(implode('/', array_filter([$this->settings->prefix(), $series, $name], fn ($p) => $p !== '')), '/');
    }
}
