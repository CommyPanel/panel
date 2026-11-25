<?php

namespace App\Dns;

class SystemDnsResolver implements DnsResolver
{
    public function a(string $host): array
    {
        return $this->lookup($host, DNS_A, 'ip');
    }

    public function aaaa(string $host): array
    {
        return $this->lookup($host, DNS_AAAA, 'ipv6');
    }

    public function cname(string $host): ?string
    {
        return $this->lookup($host, DNS_CNAME, 'target')[0] ?? null;
    }

    public function txt(string $host): array
    {
        $records = @dns_get_record($host.'.', DNS_TXT);

        if ($records === false) {
            return [];
        }

        // A long value is split into pieces ("entries"); the value is the pieces joined.
        return array_values(array_unique(array_map(
            fn (array $record) => isset($record['entries']) && is_array($record['entries']) ? implode('', $record['entries']) : (string) $record['txt'],
            array_filter($records, fn (array $record) => isset($record['txt']) || isset($record['entries'])),
        )));
    }

    /**
     * @return list<string>
     */
    private function lookup(string $host, int $type, string $field): array
    {
        $records = @dns_get_record($host.'.', $type);

        if ($records === false) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (array $record) => strtolower((string) $record[$field]),
            array_filter($records, fn (array $record) => isset($record[$field])),
        )));
    }
}
