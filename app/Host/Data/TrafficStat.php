<?php

namespace App\Host\Data;

/**
 * How one container did over a time range, as nginx saw the requests it proxied to it.
 * Latencies are the upper bound of the bucket they fell in (milliseconds): coarse on
 * purpose, for comparing releases rather than measuring them.
 */
final readonly class TrafficStat
{
    /**
     * @param  list<array{t: int, requests: int, errors: int}>  $series  only when asked for
     */
    public function __construct(
        public int $requests,
        public int $errors,
        public float $p50 = 0.0,
        public float $p95 = 0.0,
        public float $p99 = 0.0,
        public array $series = [],
    ) {}

    /**
     * The share of requests that failed (5xx), from 0 to 1.
     */
    public function errorRate(): float
    {
        return $this->requests === 0 ? 0.0 : $this->errors / $this->requests;
    }
}
