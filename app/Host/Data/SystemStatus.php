<?php

namespace App\Host\Data;

final readonly class SystemStatus
{
    public function __construct(
        public bool $dockerAvailable,
        public bool $nginxAvailable,
        public int $diskFreeBytes,
        public int $diskTotalBytes = 0,
    ) {}
}
