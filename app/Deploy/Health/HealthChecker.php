<?php

namespace App\Deploy\Health;

interface HealthChecker
{
    /**
     * Wait until the app answers on the given localhost port.
     */
    public function waitUntilHealthy(int $port, string $path, int $timeoutSeconds): bool;

    /**
     * Ask once, with a short timeout, whether the app answers right now.
     */
    public function probe(int $port, string $path): bool;
}
