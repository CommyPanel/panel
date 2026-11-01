<?php

namespace App\Host\Data;

final readonly class ContainerInfo
{
    /**
     * @param  list<array{name: string, ip: string}>  $networks
     * @param  list<array{container: int, host: int, host_ip: string, protocol: string}>  $ports  published ports only
     * @param  list<array{source: string, destination: string, read_only: bool}>  $mounts  source is relative to the volumes directory
     */
    public function __construct(
        public string $name,
        public string $image,
        public string $state,
        public string $status,
        public int $created = 0,
        public array $networks = [],
        public array $ports = [],
        public array $mounts = [],
    ) {}
}
