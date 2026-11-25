<?php

namespace App\Dns;

use App\Enums\DomainStatus;

class DomainVerifier
{
    private const MAX_CNAME_HOPS = 5;

    public function __construct(private readonly DnsResolver $dns, private readonly ServerAddress $server) {}

    public function check(string $domain): DomainCheck
    {
        $serverV4 = $this->server->ipv4();
        $serverV6 = $this->server->ipv6();

        if ($serverV4 === null && $serverV6 === null) {
            return new DomainCheck(DomainStatus::Pending, problems: ['Set this server\'s public IP address first, so there is something to point the domain at.']);
        }

        $wildcard = DomainName::isWildcard($domain);

        [$a, $aaaa] = $this->resolve(DomainName::probe($domain));

        if ($a === [] && $aaaa === []) {
            return new DomainCheck(DomainStatus::Pending, problems: [$wildcard ? 'No wildcard A or AAAA record (name *) found yet. DNS changes can take a few minutes to spread.' : 'No A or AAAA record found yet. DNS changes can take a few minutes to spread.']);
        }

        $found = [...$a, ...$aaaa];

        if (array_filter($found, fn (string $ip) => ! Cloudflare::owns($ip)) === []) {
            return new DomainCheck(DomainStatus::Proxied, $a, $aaaa, [
                $wildcard
                    ? 'This wildcard points at Cloudflare\'s proxy, so its real destination cannot be checked. A certificate for a wildcard is checked through DNS, so the proxy can stay on.'
                    : 'This domain points at Cloudflare\'s proxy, so its real destination cannot be checked. Switch the record to "DNS only" (grey cloud) until the certificate has been issued.',
            ]);
        }

        $problems = [];

        if ($serverV4 !== null && ! in_array($serverV4, $a, true)) {
            $problems[] = $a === []
                ? ($wildcard ? 'Add a wildcard A record (name *)' : 'Add an A record')." pointing to {$serverV4}."
                : 'The A record points to '.implode(', ', $a)." instead of {$serverV4}.";
        }

        // With no IPv4 address configured, the guard above guarantees an IPv6 one.
        if ($serverV4 === null && ! in_array($serverV6, $aaaa, true)) {
            $problems[] = ($wildcard ? 'Add a wildcard AAAA record (name *)' : 'Add an AAAA record')." pointing to {$serverV6}.";
        }

        // An AAAA record that points elsewhere would send IPv6 visitors (and
        // Let's Encrypt) to the wrong server.
        if ($aaaa !== [] && ($serverV6 === null || ! in_array($serverV6, $aaaa, true))) {
            $problems[] = $serverV6 === null
                ? 'An AAAA record exists ('.implode(', ', $aaaa).') but this server has no IPv6 address configured. Remove it, or set the server\'s IPv6 address.'
                : 'The AAAA record points to '.implode(', ', $aaaa)." instead of {$serverV6}.";
        }

        return new DomainCheck($problems === [] ? DomainStatus::Verified : DomainStatus::Misconfigured, $a, $aaaa, $problems);
    }

    /**
     * Follow CNAMEs until addresses are found.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function resolve(string $host): array
    {
        for ($hop = 0; $hop <= self::MAX_CNAME_HOPS; $hop++) {
            $a = $this->dns->a($host);
            $aaaa = $this->dns->aaaa($host);

            if ($a !== [] || $aaaa !== []) {
                return [$a, $aaaa];
            }

            $target = $this->dns->cname($host);

            if ($target === null) {
                break;
            }

            $host = rtrim($target, '.');
        }

        return [[], []];
    }
}
