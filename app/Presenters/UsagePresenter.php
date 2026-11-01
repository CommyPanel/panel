<?php

namespace App\Presenters;

use App\Host\Data\ContainerUsage;

/**
 * Shapes resource readings into the JSON the usage charts poll for.
 */
class UsagePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function container(ContainerUsage $c): array
    {
        return [
            'name' => $c->name,
            't' => $c->t,
            'cpu_percent' => $c->cpuPercent,
            'mem_used' => $c->memUsed,
            'mem_limit' => $c->memLimit,
            'net_rx_bps' => $c->netRxBps,
            'net_tx_bps' => $c->netTxBps,
            'history' => $c->history,
        ];
    }
}
