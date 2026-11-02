<?php

namespace App\Host;

use App\Host\Exceptions\HostOperationFailed;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the agent and Docker are up, and how full the disk is, for the warning
 * every page shows when something is wrong. Cached briefly so pages do not ask
 * the agent on every visit.
 */
class HostHealth
{
    private const KEY = 'commypanel.host-health.v2';

    private const SECONDS = 15;

    public function __construct(private readonly HostDriver $host) {}

    /**
     * @return array{agent: bool, docker: bool, disk: array{level: string, used_percent: int, free_bytes: int, total_bytes: int}|null}
     */
    public function status(): array
    {
        /** @var array{agent: bool, docker: bool, disk: array{level: string, used_percent: int, free_bytes: int, total_bytes: int}|null} */
        return Cache::remember(self::KEY, self::SECONDS, function (): array {
            try {
                $status = $this->host->systemStatus();

                return [
                    'agent' => true,
                    'docker' => $status->dockerAvailable,
                    'disk' => DiskWarning::of($status->diskTotalBytes, $status->diskFreeBytes)?->toArray(),
                ];
            } catch (HostOperationFailed) {
                return ['agent' => false, 'docker' => false, 'disk' => null];
            }
        });
    }

    /**
     * Ask again on the next page, e.g. after Docker was restarted.
     */
    public function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
