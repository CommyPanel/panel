<?php

namespace App\Deploy;

use App\Enums\Stack;

/**
 * The script a release runs before it goes live (`apps.release_script`), several lines, run by `sh -ec`.
 */
final class ReleaseScripts
{
    public const MAX_LENGTH = 16384;

    /**
     * Whether an app of this stack can run a release script. The script runs by `sh -ec` in the app's own
     * image, so the image needs a shell: the images CommyPanel builds for Laravel, Node, .NET and Python have
     * one, and an app's own Dockerfile usually does. The Go image has none (it is distroless), and a quick
     * install runs a ready-made image: both keep the single release command.
     */
    public static function supports(?Stack $stack): bool
    {
        return in_array($stack, [Stack::Laravel, Stack::Node, Stack::Python, Stack::Dotnet, Stack::Dockerfile], true);
    }

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
