<?php

namespace App\Dns;

final class DomainName
{
    /** The label looked up to see where a wildcard name points: any name under it would do. */
    public const PROBE_LABEL = 'commypanel-check';

    public static function isWildcard(string $name): bool
    {
        return str_starts_with($name, '*.');
    }

    /**
     * Like normalize(), but a name may be a wildcard (`*.example.com`: every name one level below
     * example.com). The part after `*.` must itself be a plain domain, so `*.com` is refused.
     */
    public static function normalizeAllowingWildcard(string $input): ?string
    {
        $input = trim($input);

        if (! str_starts_with($input, '*.')) {
            return self::normalize($input);
        }

        $base = self::normalize(substr($input, 2));

        return $base === null ? null : '*.'.$base;
    }

    /** The name to look up in DNS for a domain: a wildcard is checked through one name below it. */
    public static function probe(string $name): string
    {
        return self::isWildcard($name) ? self::PROBE_LABEL.'.'.substr($name, 2) : $name;
    }

    /**
     * Normalise user input to a lowercase ASCII hostname, or null when it is
     * not a plain domain name (IP addresses, wildcards, URLs and single labels
     * are all refused).
     */
    public static function normalize(string $input): ?string
    {
        $name = rtrim(strtolower(trim($input)), '.');

        if (preg_match('/[^\x20-\x7e]/', $name) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $name = $ascii === false ? '' : $ascii;
        }

        if ($name === '' || strlen($name) > 253 || filter_var($name, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $labels = explode('.', $name);

        if (count($labels) < 2) {
            return null;
        }

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return null;
            }
        }

        // A purely numeric top-level label would make this look like an IP address.
        return ctype_digit(end($labels)) ? null : $name;
    }
}
