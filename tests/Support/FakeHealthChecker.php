<?php

namespace Tests\Support;

use App\Deploy\Health\HealthChecker;

class FakeHealthChecker implements HealthChecker
{
    /** @var list<array{port: int, path: string}> */
    public array $calls = [];

    /** @var list<int> the wait given to each waitUntilHealthy call */
    public array $timeouts = [];

    public function __construct(public bool $healthy = true) {}

    public function waitUntilHealthy(int $port, string $path, int $timeoutSeconds): bool
    {
        $this->calls[] = ['port' => $port, 'path' => $path];
        $this->timeouts[] = $timeoutSeconds;

        return $this->healthy;
    }

    public function probe(int $port, string $path): bool
    {
        $this->calls[] = ['port' => $port, 'path' => $path];

        return $this->healthy;
    }
}
