<?php

namespace App\Deploy\Health;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Healthy means the app answers an HTTP request with anything below 500. A
 * 404 on "/" is common for APIs and still proves the app is up.
 */
class HttpHealthChecker implements HealthChecker
{
    public function waitUntilHealthy(int $port, string $path, int $timeoutSeconds): bool
    {
        $url = $this->url($port, $path);

        for ($attempt = 0; $attempt < max(1, $timeoutSeconds); $attempt++) {
            try {
                if (Http::timeout(3)->get($url)->status() < 500) {
                    return true;
                }
            } catch (ConnectionException) {
                // Not listening yet.
            }

            Sleep::for(1)->second();
        }

        return false;
    }

    public function probe(int $port, string $path): bool
    {
        try {
            return Http::timeout(3)->get($this->url($port, $path))->status() < 500;
        } catch (ConnectionException) {
            return false;
        }
    }

    private function url(int $port, string $path): string
    {
        return "http://127.0.0.1:{$port}".(str_starts_with($path, '/') ? $path : '/'.$path);
    }
}
