<?php

namespace App\Host\Data;

/**
 * The public IP addresses on the server's own network interfaces. Empty behind a
 * NAT, where the public address is only known to the provider's router.
 */
final readonly class PublicAddresses
{
    /**
     * @param  list<string>  $ipv4
     * @param  list<string>  $ipv6
     */
    public function __construct(
        public array $ipv4 = [],
        public array $ipv6 = [],
        public ?string $primaryIpv4 = null,
        public ?string $primaryIpv6 = null,
    ) {}
}
