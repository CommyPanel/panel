<?php

namespace App\Host;

use App\Host\Exceptions\HostOperationFailed;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the agent and Docker are up, for the warning every page shows when
 * they are not. Cached briefly so pages do not ask the agent on every visit.
 */
class HostHealth
{
    private const KEY = 'commypanel.host-health';

    private const SECONDS = 15;

    public function __construct(private readonly HostDriver $host) {}

    /**
     * @return array{agent: bool, docker: bool}
     */
    public function status(): array
    {
        /** @var array{agent: bool, docker: bool} */
        return Cache::remember(self::KEY, self::SECONDS, function (): array {
            try {
                return ['agent' => true, 'docker' => $this->host->systemStatus()->dockerAvailable];
            } catch (HostOperationFailed) {
                return ['agent' => false, 'docker' => false];
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
