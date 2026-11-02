<?php

namespace App\Host;

use App\Host\Data\VolumeUsage;
use App\Host\Exceptions\HostOperationFailed;
use App\Models\App;
use Illuminate\Support\Facades\Cache;

/**
 * The app volumes that take the most space on the server, for the page that
 * helps when the disk fills up. Measuring walks every volume folder, so the
 * answer is remembered for a few minutes.
 */
class BiggestVolumes
{
    private const KEY = 'commypanel.biggest-volumes';

    private const SECONDS = 300;

    public function __construct(private readonly HostDriver $host) {}

    /**
     * @return list<array{app: string, name: string, bytes: int, files: int, partial: bool}>
     */
    public function top(int $limit = 5, bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::KEY);
        }

        /** @var list<array{app: string, name: string, bytes: int, files: int, partial: bool}> $all */
        $all = Cache::remember(self::KEY, self::SECONDS, fn (): array => $this->measure());

        return array_slice($all, 0, $limit);
    }

    /**
     * @return list<array{app: string, name: string, bytes: int, files: int, partial: bool}>
     */
    private function measure(): array
    {
        $rows = [];

        foreach (App::query()->orderBy('slug')->get(['id', 'slug']) as $app) {
            try {
                $usages = $this->host->listVolumes($app->slug);
            } catch (HostOperationFailed) {
                continue;
            }

            foreach ($usages as $usage) {
                /** @var VolumeUsage $usage */
                $rows[] = ['app' => $app->slug, 'name' => $usage->name, 'bytes' => $usage->bytes, 'files' => $usage->files, 'partial' => $usage->partial];
            }
        }

        usort($rows, fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        return $rows;
    }
}
