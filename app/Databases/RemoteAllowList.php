<?php

namespace App\Databases;

/**
 * The addresses allowed to connect to a database from the internet: real IP addresses or ranges
 * (CIDR). The agent checks them again; this turns what a person typed into the exact list.
 */
final class RemoteAllowList
{
    public const MAX = 20;

    /** The widest range accepted, so "everybody" cannot be typed by accident. */
    private const MIN_PREFIX_V4 = 16;

    private const MIN_PREFIX_V6 = 48;

    /**
     * @param  string|list<string>  $input  lines, commas or spaces between entries
     * @return list<string> normalised entries, without duplicates
     *
     * @throws DatabaseException
     */
    public static function parse(string|array $input): array
    {
        $text = is_array($input) ? implode("\n", $input) : $input;
        $entries = preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($entries as $entry) {
            $out[self::normalise($entry)] = true;
        }

        $out = array_keys($out);

        if ($out === []) {
            throw new DatabaseException(__('Add at least one IP address. Remote access is never open to everybody.'));
        }

        if (count($out) > self::MAX) {
            throw new DatabaseException(__('At most :max addresses or ranges can be allowed.', ['max' => self::MAX]));
        }

        return $out;
    }

    /**
     * @throws DatabaseException
     */
    private static function normalise(string $entry): string
    {
        $parts = explode('/', $entry);

        if (count($parts) > 2) {
            throw new DatabaseException(__(':entry is not an IP address or range.', ['entry' => $entry]));
        }

        $ip = $parts[0];
        $v4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $v6 = ! $v4 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $v4 && ! $v6) {
            throw new DatabaseException(__(':entry is not an IP address or range.', ['entry' => $entry]));
        }

        $max = $v4 ? 32 : 128;
        $prefix = $max;

        if (count($parts) === 2) {
            if (! ctype_digit($parts[1]) || (int) $parts[1] > $max) {
                throw new DatabaseException(__(':entry has an invalid range length.', ['entry' => $entry]));
            }

            $prefix = (int) $parts[1];
        }

        if ($prefix < ($v4 ? self::MIN_PREFIX_V4 : self::MIN_PREFIX_V6)) {
            throw new DatabaseException(__(':entry is too wide. Use /:prefix or narrower, or list the addresses one by one.', ['entry' => $entry, 'prefix' => $v4 ? self::MIN_PREFIX_V4 : self::MIN_PREFIX_V6]));
        }

        // Addresses that never come from the internet cannot be meant.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new DatabaseException(__(':entry is a private or reserved address. Use the address the server sees, which is your public one.', ['entry' => $entry]));
        }

        $ip = $v6 ? (string) inet_ntop((string) inet_pton($ip)) : $ip;

        return $ip.($prefix === $max ? '' : "/{$prefix}");
    }
}
