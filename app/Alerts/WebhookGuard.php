<?php

namespace App\Alerts;

use App\Dns\DnsResolver;

/**
 * Decides where the panel may send a message by itself. The address must be https, and
 * the name must resolve to addresses that are not this server, a reserved range or the
 * link-local range where cloud providers keep their metadata. A server in a private
 * network is allowed (a self-hosted chat). The checked address is the one then used
 * for the connection, so the name cannot change its answer in between.
 */
class WebhookGuard
{
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * Whether the text is an acceptable address; null when it is, else the reason.
     */
    public function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || strlen($url) > 500 || preg_match('/\s/', $url) === 1) {
            return 'Use a complete https address, such as https://hooks.slack.com/services/...';
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            return 'That address points at this server itself, which is not allowed.';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false && ! $this->allowed($host)) {
            return 'That address points at this server itself or a reserved range, which is not allowed.';
        }

        return null;
    }

    /**
     * The address to connect to for this URL, as a curl `--resolve` entry.
     *
     * @throws AlertFailed
     */
    public function pin(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = (int) ($parts['port'] ?? 443);

        if ($this->problem($url) !== null) {
            throw new AlertFailed('The address is not allowed.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [...$this->dns->a($host), ...$this->dns->aaaa($host)];

        if ($ips === []) {
            throw new AlertFailed('The address could not be found in DNS.');
        }

        foreach ($ips as $ip) {
            if (! $this->allowed($ip)) {
                throw new AlertFailed('The address points at this server itself or a reserved range, which is not allowed.');
            }
        }

        return "{$host}:{$port}:{$ips[0]}";
    }

    private function allowed(string $ip): bool
    {
        // ::ffff:127.0.0.1 is 127.0.0.1
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // link-local (cloud metadata) for both families; fe80::/10 is covered above only in part
        return ! str_starts_with($ip, '169.254.') && ! preg_match('/^fe[89ab][0-9a-f]:/i', $ip);
    }
}
