<?php

namespace App\I18n;

use Illuminate\Support\Facades\File;

/**
 * The sentences of one language, from lang/<code>.json, for the browser. English needs no file:
 * a sentence is its own key, so an empty list shows English.
 */
final class Translations
{
    /**
     * @return array<string, string>
     */
    public static function for(string $locale): array
    {
        if ($locale === Locales::DEFAULT || ! Locales::isAvailable($locale)) {
            return [];
        }

        $path = lang_path("{$locale}.json");

        if (! File::isFile($path)) {
            return [];
        }

        $data = json_decode((string) File::get($path), true);

        if (! is_array($data)) {
            return [];
        }

        // An empty translation means "not translated yet": show English.
        return array_filter($data, fn ($value) => is_string($value) && $value !== '');
    }
}
