<?php

namespace App\Deploy\Stacks\Laravel;

/**
 * Just enough of Composer's version constraints to tell which PHP minor versions a project accepts
 * (`^8.3`, `~8.2.0`, `>=8.2 <8.5`, `8.3.*`, `^8.2 || ^8.3`). Anything it does not understand counts as
 * "accepts everything", so an odd constraint never blocks a build.
 */
final class ComposerConstraint
{
    /**
     * Whether some release of this PHP minor (such as "8.4") satisfies the constraint.
     */
    public static function acceptsMinor(string $constraint, string $minor): bool
    {
        $constraint = trim(preg_replace('/@\w+/', '', $constraint) ?? '');

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            if (self::alternativeAccepts($alternative, $minor)) {
                return true;
            }
        }

        return false;
    }

    private static function alternativeAccepts(string $alternative, string $minor): bool
    {
        $alternative = trim($alternative);

        // "8.1 - 8.3" is "at least 8.1, up to and including every 8.3".
        if (preg_match('/^(\d[\w.]*)\s+-\s+(\d[\w.]*)$/', $alternative, $m) === 1) {
            return version_compare($minor.'.99', self::padded($m[1]), '>=') && version_compare($minor.'.0', self::upper($m[2]), '<');
        }

        $comparators = preg_split('/[\s,]+/', $alternative, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($comparators as $comparator) {
            if (! self::comparatorAccepts($comparator, $minor)) {
                return false;
            }
        }

        return true;
    }

    private static function comparatorAccepts(string $comparator, string $minor): bool
    {
        // Every release of the minor is tried through its first and its last patch: it is accepted when any of them is.
        foreach ([$minor.'.0', $minor.'.99'] as $version) {
            if (self::one($comparator, $version)) {
                return true;
            }
        }

        return false;
    }

    private static function one(string $comparator, string $version): bool
    {
        if (preg_match('/^(\^|~|>=|<=|==|!=|<>|>|<|=)?\s*v?(\d+(?:\.(?:\d+|\*|x))*)(?:\.\*|\.x)?(?:-[\w.]+)?$/i', $comparator, $m) !== 1) {
            return true; // not understood: do not block
        }

        $operator = $m[1];
        $raw = $m[2];
        $wild = str_contains($comparator, '*') || stripos($comparator, '.x') !== false;
        $base = self::padded(preg_replace('/\.(\*|x)$/i', '', $raw) ?? $raw);

        return match (true) {
            $operator === '^' => version_compare($version, $base, '>=') && version_compare($version, ((int) explode('.', $base)[0] + 1).'.0.0', '<'),
            $operator === '~' => version_compare($version, $base, '>=') && version_compare($version, self::tildeUpper($raw), '<'),
            $operator === '>=' => version_compare($version, $base, '>='),
            $operator === '>' => version_compare($version, self::upper($raw), '>='),
            $operator === '<=' => version_compare($version, self::upper($raw), '<'),
            $operator === '<' => version_compare($version, $base, '<'),
            $operator === '!=' || $operator === '<>' => ! version_compare($version, $base, '=='),
            // "8.3", "8.3.*", "==8.3": any release of that prefix.
            default => $wild || count(explode('.', $raw)) < 3 ? version_compare($version, $base, '>=') && version_compare($version, self::upper($raw), '<') : version_compare($version, $base, '=='),
        };
    }

    /** "8" and "8.3" become "8.0.0" and "8.3.0". */
    private static function padded(string $version): string
    {
        $parts = array_slice(explode('.', preg_replace('/[^0-9.]/', '', $version) ?? ''), 0, 3);

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_map(fn (string $p) => $p === '' ? '0' : $p, $parts));
    }

    /** The first version above every release that starts with the given prefix ("8.3" gives "8.4.0"). */
    private static function upper(string $version): string
    {
        $parts = array_values(array_filter(explode('.', preg_replace('/[^0-9.]/', '', $version) ?? ''), fn (string $p) => $p !== ''));

        if (count($parts) >= 3) {
            return self::padded(implode('.', [$parts[0], $parts[1], (int) $parts[2] + 1]));
        }

        if (count($parts) === 2) {
            return self::padded($parts[0].'.'.((int) $parts[1] + 1));
        }

        return self::padded(((int) ($parts[0] ?? 0) + 1).'.0');
    }

    /** `~8.3` allows up to (not including) 9.0; `~8.3.1` up to 8.4.0. */
    private static function tildeUpper(string $version): string
    {
        $parts = array_values(array_filter(explode('.', preg_replace('/[^0-9.]/', '', $version) ?? ''), fn (string $p) => $p !== ''));

        return count($parts) >= 3 ? self::padded($parts[0].'.'.((int) $parts[1] + 1)) : self::padded(((int) ($parts[0] ?? 0) + 1).'.0');
    }
}
