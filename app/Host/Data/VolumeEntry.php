<?php

namespace App\Host\Data;

/**
 * One item in a volume folder. `kind` is dir, file, link or other.
 */
final readonly class VolumeEntry
{
    public function __construct(
        public string $name,
        public string $kind,
        public int $size = 0,
        public int $modified = 0,
    ) {}
}
