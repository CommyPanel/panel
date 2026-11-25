<?php

namespace App\Host\Data;

use DateTimeImmutable;

/**
 * The TXT records a person must create for a wildcard order, when no DNS provider is connected.
 */
final readonly class DnsChallenge
{
    /**
     * @param  list<array{name: string, value: string}>  $records
     */
    public function __construct(
        public array $records,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
