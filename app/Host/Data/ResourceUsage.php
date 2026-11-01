<?php

namespace App\Host\Data;

/**
 * A reading of how busy the host and its managed containers are. CPU is a
 * percentage (of the whole host for the host, of one core for a container),
 * memory and disk are bytes, network is bytes per second.
 */
final readonly class ResourceUsage
{
    /**
     * @param  array{ready: bool, cpu_percent: float, cpus: int, mem_total: int, mem_used: int, disk_total: int, disk_used: int, net_rx_bps: float, net_tx_bps: float}  $host
     * @param  list<array{t: int, cpu: float, mem: int, rx: float, tx: float}>  $hostHistory  oldest first; only when asked for
     * @param  list<ContainerUsage>  $containers
     */
    public function __construct(
        public int $intervalMs,
        public int $now,
        public array $host,
        public array $hostHistory,
        public array $containers,
    ) {}
}
