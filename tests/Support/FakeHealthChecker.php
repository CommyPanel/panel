<?php

namespace Tests\Support;

use App\Deploy\Health\HealthChecker;

class FakeHealthChecker implements HealthChecker
{
    /** @var list<array{port: int, path: string}> */
    public array $calls = [];

    public function __construct(public bool $healthy = true) {}

    public function waitUntilHealthy(int $port, string $path, int $timeoutSeconds): bool
    {
        $this->calls[] = ['port' => $port, 'path' => $path];

        return $this->healthy;
    }

    public function probe(int $port, string $path): bool
    {
        $this->calls[] = ['port' => $port, 'path' => $path];

        return $this->healthy;
    }
}
