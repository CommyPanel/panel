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
     * @param  list<string>|null  $command  replaces the image's command (an argument list, never a shell line)
     * @param  int  $stopTimeout  seconds the container gets to stop on its own before it is killed; 0 for the default
     * @param  string|null  $restart  the restart policy (`unless-stopped` for a worker); null for the agent's default
     * @param  string|null  $process  the name of the process this container runs (a label), for the extra processes of an app
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
        public ?array $command = null,
        public int $stopTimeout = 0,
        public ?string $restart = null,
        public ?string $process = null,
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

        if ($command !== null && ($command === [] || count($command) > 64)) {
            throw new InvalidArgumentException('A container command needs between 1 and 64 arguments.');
        }

        if ($stopTimeout < 0 || $stopTimeout > 600) {
            throw new InvalidArgumentException('Invalid stop timeout.');
        }

        if ($restart !== null && ! in_array($restart, ['no', 'unless-stopped', 'on-failure'], true)) {
            throw new InvalidArgumentException('Invalid restart policy.');
        }

        if ($process !== null) {
            ResourceName::assertValid($process);
        }

        // A host port of 0 means nothing is published (a database server).
        $published = $hostPort !== 0;

        if ($published && ($hostPort < 1024 || $hostPort > 65535 || $containerPort < 1 || $containerPort > 65535) || ! $published && $containerPort !== 0) {
            throw new InvalidArgumentException('Invalid container port mapping.');
        }
    }
}
