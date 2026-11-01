<?php

namespace App\Host\Data;

/**
 * A volume folder on the host and the space it uses. `partial` means it held
 * too much to count completely.
 */
final readonly class VolumeUsage
{
    public function __construct(
        public string $name,
        public int $bytes,
        public int $files,
        public bool $partial = false,
    ) {}
}
