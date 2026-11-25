<?php

namespace App\Dns;

interface DnsResolver
{
    /**
     * @return list<string>
     */
    public function a(string $host): array;

    /**
     * @return list<string>
     */
    public function aaaa(string $host): array;

    public function cname(string $host): ?string;

    /**
     * The text of the TXT records at a name.
     *
     * @return list<string>
     */
    public function txt(string $host): array;
}
