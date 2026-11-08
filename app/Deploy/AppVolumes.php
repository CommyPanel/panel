<?php

namespace App\Deploy;

use App\Host\Data\Volume;
use App\Models\App;
use App\Models\AppVolume;
use InvalidArgumentException;

/**
 * Persistent volumes: folders of an app that survive deployments. Each is kept
 * on the host under <data dir>/volumes/<slug>/<name> and mounted at a path the
 * user picks in every container the app runs, so the old and the new container
 * of a blue/green switch share it. The agent checks every path again.
 */
class AppVolumes
{
    public const MAX = 8;

    /** Where a volume can never be mounted: it would hide the container's own system. */
    private const RESERVED = ['/proc', '/sys', '/dev'];

    /**
     * Normalise and check a container path, returning it without a trailing slash.
     *
     * @throws InvalidArgumentException with a message fit to show the user
     */
    public static function cleanPath(string $path): string
    {
        $path = trim($path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        if (strlen($path) > 255 || preg_match('#^/[A-Za-z0-9._/-]+$#', $path) !== 1 || str_contains($path, '..') || str_contains($path, '//')) {
            throw new InvalidArgumentException(__('Use an absolute path of letters, digits, dots, dashes and slashes, such as /app/data.'));
        }

        foreach (self::RESERVED as $reserved) {
            if ($path === $reserved || str_starts_with($path, $reserved.'/')) {
                throw new InvalidArgumentException(__('A volume cannot be mounted under :reserved.', ['reserved' => $reserved]));
            }
        }

        return $path;
    }

    /**
     * @throws InvalidArgumentException with a message fit to show the user
     */
    public function add(App $app, string $path): AppVolume
    {
        $path = self::cleanPath($path);

        if ($app->volumes()->where('path', $path)->exists()) {
            throw new InvalidArgumentException(__(':path already has a volume.', ['path' => $path]));
        }

        if ($app->volumes()->count() >= self::MAX) {
            throw new InvalidArgumentException(__('An app can have at most :max volumes.', ['max' => self::MAX]));
        }

        /** @var AppVolume */
        return $app->volumes()->create(['name' => $this->nameFor($app, $path), 'path' => $path]);
    }

    /**
     * The mounts for a container or task of this app.
     *
     * @return list<Volume>
     */
    public function mounts(App $app): array
    {
        return array_values($app->volumes()->orderBy('id')->get()->map(fn (AppVolume $v) => $v->mount($app))->all());
    }

    /**
     * A folder name from the path (/app/data becomes app-data), unique within the app.
     */
    private function nameFor(App $app, string $path): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($path)), '-');
        $base = rtrim(substr($base, 0, 28), '-');
        $base = $base === '' ? 'data' : $base;

        $name = $base;

        for ($n = 2; $app->volumes()->where('name', $name)->exists(); $n++) {
            $name = "{$base}-{$n}";
        }

        return $name;
    }
}
