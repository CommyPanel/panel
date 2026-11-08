<?php

namespace App\Deploy\Health;

/**
 * For the fake host driver only: nothing really runs, so there is nothing to ask.
 */
class AssumeHealthyChecker implements HealthChecker
{
    public function waitUntilHealthy(int $port, string $path, int $timeoutSeconds): bool
    {
        return true;
    }

    public function probe(int $port, string $path): bool
    {
        return true;
    }
}
