<?php

namespace App\Host\Data;

/**
 * How the agent proves control of a wildcard name: it creates the _acme-challenge TXT record itself
 * through a DNS provider. Only Cloudflare is supported. The token is sent for this one request and
 * the agent keeps nothing.
 */
final readonly class DnsValidation
{
    public function __construct(
        public string $provider,
        #[\SensitiveParameter] public string $token,
    ) {}
}
