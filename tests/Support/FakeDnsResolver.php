<?php

namespace Tests\Support;

use App\Dns\DnsResolver;

class FakeDnsResolver implements DnsResolver
{
    /**
     * @param  array<string, array{a?: list<string>, aaaa?: list<string>, cname?: string, txt?: list<string>}>  $records
     */
    public function __construct(public array $records = []) {}

    public function a(string $host): array
    {
        return $this->records[$host]['a'] ?? [];
    }

    public function aaaa(string $host): array
    {
        return $this->records[$host]['aaaa'] ?? [];
    }

    public function cname(string $host): ?string
    {
        return $this->records[$host]['cname'] ?? null;
    }

    public function txt(string $host): array
    {
        return $this->records[$host]['txt'] ?? [];
    }
}
