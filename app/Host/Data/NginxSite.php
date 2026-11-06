<?php

namespace App\Host\Data;

use InvalidArgumentException;

final readonly class NginxSite
{
    /**
     * @param  list<string>  $domains
     * @param  array<string, string>  $redirects  domain => the domain it permanently redirects to
     * @param  string|null  $custom  the user's extra directives; the agent checks them against an allow-list
     * @param  NginxRollout|null  $rollout  send a share of the visitors to a second container
     */
    public function __construct(
        public string $name,
        public array $domains,
        public int $upstreamPort,
        public ?string $certificate = null,
        public array $redirects = [],
        public ?string $custom = null,
        public ?NginxRollout $rollout = null,
    ) {
        ResourceName::assertValid($name);

        if ($certificate !== null) {
            ResourceName::assertValid($certificate);
        }

        if ($domains === []) {
            throw new InvalidArgumentException('An nginx site needs at least one domain.');
        }

        foreach ($domains as $domain) {
            if (! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new InvalidArgumentException("Invalid domain [{$domain}].");
            }
        }

        foreach ($redirects as $from => $to) {
            if (! in_array($from, $domains, true) || ! in_array($to, $domains, true) || $from === $to || isset($redirects[$to])) {
                throw new InvalidArgumentException("Invalid redirect [{$from}] to [{$to}].");
            }
        }

        if (count($redirects) >= count($domains)) {
            throw new InvalidArgumentException('An nginx site needs a domain that is not redirected.');
        }

        if ($upstreamPort < 1024 || $upstreamPort > 65535) {
            throw new InvalidArgumentException("Invalid upstream port [{$upstreamPort}].");
        }

        if ($rollout !== null && $rollout->port === $upstreamPort) {
            throw new InvalidArgumentException('A rollout needs a second container, on another port.');
        }
    }
}
