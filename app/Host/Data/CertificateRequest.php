<?php

namespace App\Host\Data;

use InvalidArgumentException;

final readonly class CertificateRequest
{
    /**
     * @param  list<string>  $domains  a name may be a wildcard (`*.example.com`), which needs `$dns` or the manual TXT flow
     */
    public function __construct(
        public string $name,
        public array $domains,
        public ?string $email = null,
        public bool $staging = false,
        public ?DnsValidation $dns = null,
    ) {
        ResourceName::assertValid($name);

        if ($domains === []) {
            throw new InvalidArgumentException('A certificate needs at least one domain.');
        }
    }
}
