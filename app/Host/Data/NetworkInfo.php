<?php

namespace App\Host\Data;

/**
 * A Docker network CommyPanel created, and the containers attached to it.
 */
final readonly class NetworkInfo
{
    /**
     * @param  list<array{name: string, ip: string}>  $containers
     */
    public function __construct(
        public string $name,
        public string $driver,
        public string $subnet = '',
        public string $gateway = '',
        public bool $internal = false,
        public array $containers = [],
    ) {}
}
