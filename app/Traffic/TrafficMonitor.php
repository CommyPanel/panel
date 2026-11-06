<?php

namespace App\Traffic;

use App\Host\Data\TrafficStat;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Deployment;

/**
 * Reads how an app's releases are doing from the agent. When the agent has no statistics
 * (an older agent, or turned off) everything reads as "no traffic": nothing is ever judged
 * bad on missing data.
 */
class TrafficMonitor
{
    public function __construct(private readonly HostDriver $host) {}

    /**
     * Every container of the app that served requests in the range, by port.
     *
     * @return array<int, TrafficStat>
     */
    public function ranges(App $app, int $from, int $to, int $step = 0): array
    {
        if ($to <= $from) {
            return [];
        }

        try {
            return $this->host->trafficStats($app->slug, $from, $to, $step);
        } catch (HostOperationFailed) {
            return [];
        }
    }

    /**
     * What one release's container did over a range, or null when it served nothing.
     */
    public function stat(App $app, Deployment $deployment, int $from, int $to): ?TrafficStat
    {
        if ($deployment->host_port === null) {
            return null;
        }

        return $this->ranges($app, $from, $to)[$deployment->host_port] ?? null;
    }
}
