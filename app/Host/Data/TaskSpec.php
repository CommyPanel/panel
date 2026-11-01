<?php

namespace App\Host\Data;

use InvalidArgumentException;

/**
 * A one-off command run to completion in a fresh container from one of the
 * panel's own images, such as an app's release command. The command is an
 * argument list; no shell is involved unless the user names one.
 */
final readonly class TaskSpec
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @param  list<Volume>  $volumes
     */
    public function __construct(
        public string $name,
        public string $image,
        public array $command,
        public array $env = [],
        public ?int $memoryLimitMb = null,
        public ?float $cpuLimit = null,
        public array $volumes = [],
        public ?string $network = null,
        public int $timeoutSeconds = 600,
    ) {
        ResourceName::assertValid($name);

        if (! str_starts_with($image, 'commypanel/')) {
            throw new InvalidArgumentException('Tasks only run from images CommyPanel built.');
        }

        if ($command === [] || $command[0] === '') {
            throw new InvalidArgumentException('A task needs a command.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > 3600) {
            throw new InvalidArgumentException('Task timeout must be between 1 and 3600 seconds.');
        }
    }
}
