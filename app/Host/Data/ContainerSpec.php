<?php

namespace App\Host\Data;

use InvalidArgumentException;

final readonly class ContainerSpec
{
    /**
     * @param  array<string, string>  $env
     * @param  list<Volume>  $volumes
     * @param  list<string>  $aliases  extra names on its own network
     * @param  list<NetworkAttachment>  $networks  further networks to join
     */
    public function __construct(
        public string $name,
        public string $image,
        public int $hostPort,
        public int $containerPort,
        public array $env = [],
        public ?int $memoryLimitMb = null,
        public ?float $cpuLimit = null,
        public array $volumes = [],
        public ?string $network = null,
        public array $aliases = [],
        public array $networks = [],
        public bool $databaseTls = false,
    ) {
        ResourceName::assertValid($name);

        foreach (array_keys($env) as $key) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key)) {
                throw new InvalidArgumentException("Invalid environment variable name [{$key}].");
            }
        }

        if ($network !== null) {
            ResourceName::assertValid($network);
        }

        foreach ($aliases as $alias) {
            ResourceName::assertValid($alias);
        }

        // A host port of 0 means nothing is published (a database server).
        $published = $hostPort !== 0;

        if ($published && ($hostPort < 1024 || $hostPort > 65535 || $containerPort < 1 || $containerPort > 65535) || ! $published && $containerPort !== 0) {
            throw new InvalidArgumentException('Invalid container port mapping.');
        }
    }
}
