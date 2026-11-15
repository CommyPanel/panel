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

    public function waitUntilPingAnswers(int $port, int $timeoutSeconds): bool
    {
        for ($attempt = 0; $attempt < max(1, $timeoutSeconds); $attempt++) {
            if ($this->probePing($port)) {
                return true;
            }

            Sleep::for(1)->second();
        }

        return false;
    }

    /**
     * Redis answers `PING` with +PONG, or with -NOAUTH when a password is set; either proves it is
     * up. Anything else (nothing, a closed connection, -LOADING while it reads its data) is not yet.
     */
    public function probePing(int $port): bool
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 3);

        if ($socket === false) {
            return false;
        }

        try {
            stream_set_timeout($socket, 3);
            fwrite($socket, "PING\r\n");
            $line = fgets($socket, 128);

            return is_string($line) && (str_starts_with($line, '+PONG') || str_starts_with($line, '-NOAUTH'));
        } finally {
            fclose($socket);
        }
    }

    private function url(int $port, string $path): string
    {
        return "http://127.0.0.1:{$port}".(str_starts_with($path, '/') ? $path : '/'.$path);
    }
}
