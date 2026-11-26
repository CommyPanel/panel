<?php

namespace App\Deploy\Stacks;

/**
 * The files a package manager needs next to package.json to install dependencies the same way the
 * project does, for the install layer of a build: the lock file, and the settings that change what the
 * lock file means (pnpm reads overrides, catalogs and patches from pnpm-workspace.yaml, registries and
 * hoisting from .npmrc, hooks from .pnpmfile.cjs; Yarn reads .yarnrc.yml, its releases and plugins).
 * Without them a frozen install can refuse the lock file.
 *
 * Only what exists in the source tree is copied. Copying these first and the rest of the code after keeps
 * the install layer cached until one of them changes.
 */
final class NodeInstall
{
    /** Files by package manager, in the order they are listed in the Dockerfile. */
    private const FILES = [
        'npm' => ['package.json', 'package-lock.json', 'npm-shrinkwrap.json', '.npmrc'],
        'pnpm' => ['package.json', 'pnpm-lock.yaml', 'pnpm-workspace.yaml', '.npmrc', '.pnpmfile.cjs'],
        'yarn' => ['package.json', 'yarn.lock', '.npmrc', '.yarnrc', '.yarnrc.yml'],
    ];

    /** Folders by package manager, copied whole when they exist. */
    private const DIRECTORIES = [
        'npm' => [],
        'pnpm' => ['patches'],
        'yarn' => ['.yarn/releases', '.yarn/plugins', '.yarn/patches'],
    ];

    /**
     * Dockerfile COPY lines (each ending in a newline) for the install layer.
     */
    public static function copyLines(string $path, string $manager): string
    {
        $files = array_values(array_filter(self::FILES[$manager] ?? self::FILES['npm'], fn (string $f) => SourceTree::has($path, $f)));
        $lines = $files === [] ? '' : 'COPY '.implode(' ', $files)." ./\n";

        foreach (self::DIRECTORIES[$manager] ?? [] as $directory) {
            if (is_dir($path.'/'.$directory) && ! is_link($path.'/'.$directory)) {
                $lines .= "COPY {$directory} ./{$directory}\n";
            }
        }

        return $lines;
    }
}
