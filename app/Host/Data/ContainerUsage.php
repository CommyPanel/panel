<?php

namespace App\Host\Data;

final readonly class ContainerUsage
{
    /**
     * @param  float  $cpuPercent  of one core: 100 is one full core
     * @param  list<array{t: int, cpu: float, mem: int, rx: float, tx: float}>  $history  oldest first; only when asked for
     */
    public function __construct(
        public string $name,
        public int $t,
        public float $cpuPercent,
        public int $memUsed,
        public int $memLimit,
        public float $netRxBps,
        public float $netTxBps,
        public array $history = [],
    ) {}
}
