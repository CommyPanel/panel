<?php

namespace App\Traffic;

use App\Host\Data\TrafficStat;

/**
 * What the requests say about a new release: it is fine, it is clearly worse than the
 * release it replaced, or there is not enough traffic yet to tell.
 */
final readonly class Verdict
{
    public const OK = 'ok';

    public const BAD = 'bad';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $state,
        public string $reason,
        public ?TrafficStat $new = null,
        public ?TrafficStat $reference = null,
    ) {}

    public function isBad(): bool
    {
        return $this->state === self::BAD;
    }

    public function isOk(): bool
    {
        return $this->state === self::OK;
    }

    public function isUnknown(): bool
    {
        return $this->state === self::UNKNOWN;
    }
}
