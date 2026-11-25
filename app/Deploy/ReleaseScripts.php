<?php

namespace App\Deploy;

use App\Enums\Stack;

/**
 * The script a release runs before it goes live (`apps.release_script`), several lines, run by `sh -ec`.
 */
final class ReleaseScripts
{
    public const MAX_LENGTH = 16384;

    /** What a new app of this stack starts with, or null for no script. */
    public static function defaultFor(?Stack $stack): ?string
    {
        return match ($stack) {
            Stack::Laravel => "php artisan migrate --force\n",
            default => null,
        };
    }

    /** Line breaks as the shell wants them, and nothing that cannot be text. */
    public static function normalize(?string $script): ?string
    {
        if ($script === null) {
            return null;
        }

        $script = str_replace(["\r\n", "\r"], "\n", $script);

        return trim($script) === '' ? null : rtrim($script)."\n";
    }
}
