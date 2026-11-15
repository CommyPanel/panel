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

    /**
     * Like waitUntilHealthy, for an app that speaks Redis instead of HTTP: healthy once it answers a PING.
     * A connection that is accepted and closed does not count: Docker's port proxy accepts before the app listens.
     */
    public function waitUntilPingAnswers(int $port, int $timeoutSeconds): bool;

    public function probePing(int $port): bool;
}
