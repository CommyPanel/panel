<?php

namespace App\Backups\Remote;

use App\Jobs\UploadBackupJob;
use Illuminate\Support\Facades\Log;
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

    public function __construct(private readonly RemoteSettings $settings) {}

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
