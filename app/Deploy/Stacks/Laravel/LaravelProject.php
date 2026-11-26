<?php

namespace App\Deploy\Stacks\Laravel;

use App\Deploy\Stacks\DetectionFailed;
use App\Deploy\Stacks\SourceTree;

/**
 * What the build and the wizard need to know about a Laravel source tree, read from files only
 * (composer.json and .lock, package.json, bootstrap/app.php): nothing in the project is ever executed.
 */
final class LaravelProject
{
    public const PHP_VERSIONS = ['8.2', '8.3', '8.4', '8.5'];

    public const DEFAULT_PHP = '8.4';

    public const NODE_MANAGERS = ['npm', 'pnpm', 'yarn'];

    public const DEFAULT_NODE = 22;

    /** Extensions most Laravel apps want that the base image does not have. */
    public const DEFAULT_EXTENSIONS = ['bcmath', 'exif', 'gd', 'intl', 'opcache', 'pcntl', 'pdo_mysql', 'pdo_pgsql', 'redis', 'zip'];

    /** Extensions the PHP image is built with: asking for them needs nothing. */
    private const BUILT_IN = [
        'core', 'ctype', 'curl', 'date', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'json', 'libxml', 'mbstring', 'mysqlnd', 'openssl',
        'pcre', 'pdo', 'pdo_sqlite', 'phar', 'posix', 'random', 'readline', 'reflection', 'session', 'simplexml', 'sodium', 'spl',
        'sqlite3', 'standard', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zlib',
    ];

    /** @var array<string, mixed> */
    private array $composer;

    /** @var array<string, mixed> */
    private array $lock;

    /** @var array<string, mixed>|null */
    private ?array $package;

    public function __construct(private readonly string $path)
    {
        $composer = json_decode((string) self::read($path, 'composer.json'), true);

        if (! is_array($composer)) {
            throw new DetectionFailed('composer.json is not valid JSON.');
        }

        $this->composer = $composer;
        $lock = json_decode((string) self::read($path, 'composer.lock'), true);
        $this->lock = is_array($lock) ? $lock : [];
        $package = json_decode((string) self::read($path, 'package.json'), true);
        $this->package = is_array($package) ? $package : null;
    }

    /** Largest file read for inspection: a project cannot make the panel read gigabytes. */
    private const MAX_FILE_BYTES = 8_000_000;

    /** A file's contents, or null when it is missing or unreasonably large. */
    public static function read(string $root, string $relative): ?string
    {
        $file = $root.'/'.$relative;

        if (! is_file($file) || is_link($file) || filesize($file) > self::MAX_FILE_BYTES) {
            return null;
        }

        return (string) file_get_contents($file);
    }

    /** A Laravel application: composer.json requires the framework and there is an artisan file. */
    public static function matches(string $path): bool
    {
        if (! SourceTree::has($path, 'composer.json') || ! SourceTree::has($path, 'artisan')) {
            return false;
        }

        $composer = json_decode((string) self::read($path, 'composer.json'), true);

        return is_array($composer) && isset($composer['require']['laravel/framework']);
    }

    /** Whether composer.json (not only the lock file) asks for the package. */
    public function requires(string $package): bool
    {
        return isset($this->composer['require'][$package]) || isset($this->composer['require-dev'][$package]);
    }

    /** Whether a package is installed according to composer.lock or required by composer.json. */
    public function has(string $package): bool
    {
        if ($this->requires($package)) {
            return true;
        }

        foreach ($this->lock['packages'] ?? [] as $installed) {
            if (is_array($installed) && ($installed['name'] ?? null) === $package) {
                return true;
            }
        }

        return false;
    }

    /** The framework's major version, from the lock file (v11.9.0) or else from the constraint. */
    public function laravelMajor(): ?int
    {
        foreach ($this->lock['packages'] ?? [] as $installed) {
            if (is_array($installed) && ($installed['name'] ?? null) === 'laravel/framework' && preg_match('/(\d+)\./', (string) ($installed['version'] ?? ''), $m) === 1) {
                return (int) $m[1];
            }
        }

        if (preg_match('/(\d+)/', (string) ($this->composer['require']['laravel/framework'] ?? ''), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * The PHP minor to build with: the chosen one, else the newest default that composer.json accepts.
     *
     * @throws DetectionFailed when the project accepts none of the supported versions
     */
    public function phpVersion(?string $chosen = null): string
    {
        if ($chosen !== null && in_array($chosen, self::PHP_VERSIONS, true)) {
            return $chosen;
        }

        $constraint = (string) ($this->composer['config']['platform']['php'] ?? $this->composer['require']['php'] ?? '');
        $accepted = array_values(array_filter(self::PHP_VERSIONS, fn (string $v) => ComposerConstraint::acceptsMinor($constraint, $v)));

        if ($accepted === []) {
            throw new DetectionFailed("composer.json asks for PHP {$constraint}, and CommyPanel builds with PHP ".implode(', ', self::PHP_VERSIONS).'. Add a Dockerfile to use another version.');
        }

        return in_array(self::DEFAULT_PHP, $accepted, true) ? self::DEFAULT_PHP : (string) end($accepted);
    }

    /**
     * The PHP extensions to install: what the project and its installed packages ask for (`ext-*`) and the usual defaults.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        $names = [];
        $collect = function (mixed $require) use (&$names): void {
            foreach (array_keys(is_array($require) ? $require : []) as $key) {
                if (str_starts_with((string) $key, 'ext-')) {
                    $names[] = strtolower(substr((string) $key, 4));
                }
            }
        };

        $collect($this->composer['require'] ?? []);

        foreach ($this->lock['packages'] ?? [] as $installed) {
            $collect(is_array($installed) ? ($installed['require'] ?? []) : []);
        }

        $names = array_map(fn (string $n) => $n === 'zend-opcache' ? 'opcache' : $n, $names);
        $wanted = array_values(array_unique([...self::DEFAULT_EXTENSIONS, ...array_filter($names, fn (string $n) => ! in_array($n, self::BUILT_IN, true))]));

        // The names end up in a Dockerfile.
        $wanted = array_values(array_filter($wanted, fn (string $n) => preg_match('/^[a-z0-9_]{2,40}$/', $n) === 1));
        sort($wanted);

        return $wanted;
    }

    public function hasPackageJson(): bool
    {
        return $this->package !== null;
    }

    public function hasBuildScript(): bool
    {
        return is_string($this->package['scripts']['build'] ?? null);
    }

    public function hasSsrBuild(): bool
    {
        return is_string($this->package['scripts']['build:ssr'] ?? null);
    }

    /** npm, pnpm or yarn: what package.json declares, else which lock file there is, else npm. */
    public function nodeManager(): string
    {
        $declared = is_string($this->package['packageManager'] ?? null) ? explode('@', $this->package['packageManager'])[0] : '';

        return match (true) {
            in_array($declared, self::NODE_MANAGERS, true) => $declared,
            SourceTree::has($this->path, 'pnpm-lock.yaml') => 'pnpm',
            SourceTree::has($this->path, 'yarn.lock') => 'yarn',
            default => 'npm',
        };
    }

    public function nodeMajor(): int
    {
        $spec = $this->package['engines']['node'] ?? null;
        $spec = is_string($spec) ? $spec : (string) self::read($this->path, '.nvmrc');

        if (preg_match('/(\d+)/', $spec, $m) === 1 && (int) $m[1] >= 18 && (int) $m[1] <= 30) {
            return (int) $m[1];
        }

        return self::DEFAULT_NODE;
    }

    /**
     * Whether building the front end needs PHP: the build runs `php artisan` itself, or a plugin does.
     * That is the case when any of these holds:
     *  - package.json lists a package whose name contains "wayfinder" (Laravel Wayfinder's Vite plugin
     *    runs `php artisan wayfinder:generate` when a build starts) or `vite-plugin-run`;
     *  - the build script, or a script it runs (`npm run x`, `pnpm x`, `yarn x`, and the pre and post
     *    scripts), mentions `artisan` or `php`;
     *  - the Vite config mentions `artisan` or `wayfinder`.
     * The build script is `build:ssr` when the SSR bundle is built, else `build`.
     */
    public function assetBuildNeedsPhp(bool $ssr = false): bool
    {
        foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $section) {
            foreach (array_keys(is_array($this->package[$section] ?? null) ? $this->package[$section] : []) as $name) {
                if (str_contains(strtolower((string) $name), 'wayfinder') || $name === 'vite-plugin-run') {
                    return true;
                }
            }
        }

        if (preg_match('/\b(artisan|php)\b/', $this->scriptText($ssr ? 'build:ssr' : 'build')) === 1) {
            return true;
        }

        foreach (['js', 'ts', 'mjs', 'mts', 'cjs', 'cts'] as $extension) {
            if (preg_match('/artisan|wayfinder/i', (string) self::read($this->path, "vite.config.{$extension}")) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the PHP packages the front-end build uses are only in `require-dev`, so the build needs a
     * vendor folder with the development packages: Laravel Wayfinder in `require-dev` only, or a build
     * that calls `artisan` while the project has development packages (a command may come from one).
     */
    public function assetBuildNeedsDevVendor(bool $ssr = false): bool
    {
        if (isset($this->composer['require-dev']['laravel/wayfinder']) && ! isset($this->composer['require']['laravel/wayfinder'])) {
            return true;
        }

        $dev = $this->composer['require-dev'] ?? [];

        if (! is_array($dev) || $dev === []) {
            return false;
        }

        $viteConfigs = '';

        foreach (['js', 'ts', 'mjs', 'mts', 'cjs', 'cts'] as $extension) {
            $viteConfigs .= (string) self::read($this->path, "vite.config.{$extension}");
        }

        return str_contains($this->scriptText($ssr ? 'build:ssr' : 'build'), 'artisan') || str_contains($viteConfigs, 'artisan');
    }

    /** The text of a package.json script and of the scripts it runs (to a few levels), with its pre and post scripts. */
    private function scriptText(string $name, int $depth = 0): string
    {
        $scripts = is_array($this->package['scripts'] ?? null) ? $this->package['scripts'] : [];
        $text = '';

        foreach (["pre{$name}", $name, "post{$name}"] as $key) {
            if (! is_string($scripts[$key] ?? null)) {
                continue;
            }

            $text .= ' '.$scripts[$key];

            if ($depth < 5 && preg_match_all('/(?:npm run|pnpm run|pnpm|yarn run|yarn)\s+([\w:.-]+)/', $scripts[$key], $matches) > 0) {
                foreach ($matches[1] as $next) {
                    if ($next !== $name && isset($scripts[$next])) {
                        $text .= ' '.$this->scriptText($next, $depth + 1);
                    }
                }
            }
        }

        return $text;
    }

    /** `/up` when the app registers a health route (the default skeleton does since Laravel 11), else `/`. */
    public function healthPath(): string
    {
        $bootstrap = (string) self::read($this->path, 'bootstrap/app.php');

        if (preg_match('/health\s*:\s*[\'"](\/[A-Za-z0-9\/_.-]*)[\'"]/', $bootstrap, $m) === 1) {
            return $m[1];
        }

        return '/';
    }

    /** Whether laravel/octane is installed (it needs a server of its own, which this image does not run). */
    public function octaneInstalled(): bool
    {
        return $this->has('laravel/octane');
    }

    /**
     * @return array<string, mixed>
     */
    public function composer(): array
    {
        return $this->composer;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function package(): ?array
    {
        return $this->package;
    }

    public function path(): string
    {
        return $this->path;
    }
}
