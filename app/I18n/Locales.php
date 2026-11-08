<?php

namespace App\I18n;

use Illuminate\Http\Request;

/**
 * The languages the panel's interface is available in. English is the default and the fallback:
 * anything unknown, or a translation that is missing, shows English.
 */
final class Locales
{
    public const DEFAULT = 'en';

    /**
     * @return array<string, string> code => the language's own name
     */
    public static function available(): array
    {
        /** @var array<string, string> $locales */
        $locales = (array) config('commypanel.locales', [self::DEFAULT => 'English']);

        return $locales;
    }

    public static function isAvailable(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::available());
    }

    /** A language the panel has, or English. */
    public static function resolve(?string $code): string
    {
        return self::isAvailable($code) ? (string) $code : self::DEFAULT;
    }

    /**
     * The best available language for a browser's Accept-Language header, or null if none is offered.
     * "pt-BR, fr;q=0.8, en;q=0.5" gives "fr" when Portuguese is not available.
     */
    public static function fromRequest(Request $request): ?string
    {
        $header = (string) $request->header('Accept-Language', '');
        $wanted = [];

        foreach (explode(',', $header) as $position => $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));
            $quality = 1.0;

            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $parameter, $m) === 1) {
                    $quality = (float) $m[1];
                }
            }

            if ($tag !== '' && $tag !== '*' && $quality > 0) {
                $wanted[] = [$tag, $quality, $position];
            }
        }

        usort($wanted, fn (array $a, array $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        foreach ($wanted as [$tag]) {
            $language = explode('-', $tag)[0];

            if (self::isAvailable($language)) {
                return $language;
            }
        }

        return null;
    }
}
