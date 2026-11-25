<?php

namespace App\Deploy\Stacks\Laravel;

use App\Deploy\Stacks\DetectionFailed;
use App\Deploy\Stacks\SourceTree;

/**
 * Looks at a Laravel source tree for the wizard: which services the app seems to need (a queue worker,
 * Horizon, Pulse, Inertia SSR), what the build will use, and what is worth keeping in a volume. It
 * reads files only; nothing of the project runs.
 *
 * The result is stored as `apps.detected`:
 *
 *   laravel_major     int|null     from composer.lock, else the constraint
 *   php               string       the PHP minor the build would use ("8.4")
 *   php_error         string|null  set instead of `php` being usable when composer.json accepts no supported version
 *   extensions        list<string> PHP extensions the image installs
 *   has_package_json, has_build_script, has_ssr_build   bool
 *   node_manager      npm|pnpm|yarn|null   (null without package.json)
 *   node              int          Node major for the asset build
 *   health_path       string       "/up" when bootstrap/app.php registers it, else "/"
 *   warnings          list<string> things the person should know before building (Octane needs its own Dockerfile)
 *   db_default        string|null  the connection config/database.php falls back to
 *   db_connections    list<string> the SQL connections config/database.php defines (mysql, mariadb, pgsql, sqlite, sqlsrv)
 *   queue_default     string|null  the queue connection config/queue.php falls back to
 *   services          list of {kind, name, detected_by, default_command, supported, default_enabled, overlap, needs_node, note, env_note}
 *   suggested_volumes list of {path, why}   container paths
 *   env_hints         list<string> variable NAMES found in .env.example (never values)
 */
final class LaravelInspector
{
    /** The app's directory inside the image. */
    public const APP_ROOT = '/app';

    private const MAX_ENV_HINTS = 200;

    /**
     * @return array<string, mixed>
     */
    public function inspect(string $path): array
    {
        $project = new LaravelProject($path);

        $php = null;
        $phpError = null;

        try {
            $php = $project->phpVersion();
        } catch (DetectionFailed $e) {
            $phpError = $e->getMessage();
        }

        return [
            'laravel_major' => $project->laravelMajor(),
            'php' => $php,
            'php_error' => $phpError,
            'extensions' => $project->extensions(),
            'has_package_json' => $project->hasPackageJson(),
            'has_build_script' => $project->hasBuildScript(),
            'has_ssr_build' => $project->hasSsrBuild(),
            'node_manager' => $project->hasPackageJson() ? $project->nodeManager() : null,
            'node' => $project->nodeMajor(),
            'health_path' => $project->healthPath(),
            'warnings' => $project->octaneInstalled()
                ? ['laravel/octane is installed, but this app is built with nginx and PHP-FPM. To run Octane, add a Dockerfile to the repository.']
                : [],
            'db_default' => $this->configDefault($path, 'config/database.php', 'DB_CONNECTION'),
            'db_connections' => $this->connections($path),
            'queue_default' => $this->configDefault($path, 'config/queue.php', 'QUEUE_CONNECTION'),
            'services' => $this->services($project),
            'suggested_volumes' => [
                ['path' => self::APP_ROOT.'/storage', 'why' => 'Uploads, sessions, cache files and logs are written here. Without a volume they are lost at every deployment.'],
            ],
            'env_hints' => $this->envHints($path),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function services(LaravelProject $project): array
    {
        $horizon = $project->has('laravel/horizon');

        $services = [
            [
                'kind' => 'scheduler', 'name' => 'scheduler', 'detected_by' => 'Laravel',
                'default_command' => 'php artisan schedule:work', 'supported' => true, 'default_enabled' => true,
                'overlap' => 'stop_first', 'needs_node' => false,
                'note' => 'Runs the tasks in routes/console.php every minute. Only one copy may run, so a new release starts it after the old one stopped.',
                'env_note' => null,
            ],
            [
                'kind' => 'queue', 'name' => 'queue', 'detected_by' => $horizon ? 'Laravel (Horizon manages the queues instead)' : 'Laravel',
                'default_command' => 'php artisan queue:work --tries=3 --timeout=90', 'supported' => true, 'default_enabled' => false,
                'overlap' => 'overlap', 'needs_node' => false,
                'note' => $horizon
                    ? 'You have Horizon, which runs your queues. A plain worker is only needed for a connection Horizon does not manage.'
                    : 'Processes queued jobs. It does nothing while QUEUE_CONNECTION is sync.',
                'env_note' => null,
            ],
        ];

        if ($horizon) {
            $services[] = [
                'kind' => 'horizon', 'name' => 'horizon', 'detected_by' => 'laravel/horizon',
                'default_command' => 'php artisan horizon', 'supported' => true, 'default_enabled' => false,
                'overlap' => 'stop_first', 'needs_node' => false,
                'note' => 'Runs and balances the queue workers. It needs Redis (link a Redis app) and QUEUE_CONNECTION=redis. Only one Horizon may run.',
                'env_note' => null,
            ];
        }

        if ($project->has('laravel/pulse')) {
            $services[] = [
                'kind' => 'pulse', 'name' => 'pulse', 'detected_by' => 'laravel/pulse',
                'default_command' => 'php artisan pulse:check', 'supported' => true, 'default_enabled' => false,
                'overlap' => 'stop_first', 'needs_node' => false,
                'note' => 'Collects the server readings for the Pulse dashboard. Only one copy may run.',
                'env_note' => null,
            ];
        }

        if ($this->usesInertiaSsr($project)) {
            $services[] = [
                'kind' => 'ssr', 'name' => 'ssr', 'detected_by' => 'inertiajs/inertia-laravel',
                'default_command' => 'php artisan inertia:start-ssr', 'supported' => true, 'default_enabled' => false,
                'overlap' => 'stop_first', 'needs_node' => true,
                'note' => 'Renders Inertia pages on the server. It needs the SSR build (npm run build:ssr) and Node in the image, which the build adds when this is on.',
                'env_note' => 'The web process finds it through INERTIA_SSR_URL, which must point at this process rather than at 127.0.0.1.',
            ];
        }

        if ($project->has('laravel/reverb')) {
            $services[] = [
                'kind' => 'reverb', 'name' => 'reverb', 'detected_by' => 'laravel/reverb',
                'default_command' => 'php artisan reverb:start --host=0.0.0.0 --port=8080', 'supported' => false, 'default_enabled' => false,
                'overlap' => 'stop_first', 'needs_node' => false,
                'note' => 'Reverb needs its own public address for websockets, which CommyPanel cannot give a second process yet.',
                'env_note' => null,
            ];
        }

        return $services;
    }

    private function usesInertiaSsr(LaravelProject $project): bool
    {
        if (! $project->has('inertiajs/inertia-laravel') || ! $project->hasPackageJson()) {
            return false;
        }

        if ($project->hasSsrBuild()) {
            return true;
        }

        foreach (['js', 'ts', 'jsx', 'tsx'] as $extension) {
            if (SourceTree::has($project->path(), "resources/js/ssr.{$extension}")) {
                return true;
            }
        }

        return false;
    }

    /** The fallback of `env('NAME', 'fallback')` in a config file, or null. */
    private function configDefault(string $path, string $file, string $env): ?string
    {
        $config = (string) LaravelProject::read($path, $file);

        return preg_match('/env\(\s*[\'"]'.preg_quote($env, '/').'[\'"]\s*,\s*[\'"]([a-z_]+)[\'"]\s*\)/', $config, $m) === 1 ? $m[1] : null;
    }

    /**
     * @return list<string>
     */
    private function connections(string $path): array
    {
        $config = (string) LaravelProject::read($path, 'config/database.php');
        $found = [];

        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $name) {
            if (preg_match('/[\'"]'.$name.'[\'"]\s*=>\s*\[/', $config) === 1) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Variable names (never values) from .env.example.
     *
     * @return list<string>
     */
    private function envHints(string $path): array
    {
        $names = [];

        foreach (preg_split('/\R/', (string) LaravelProject::read($path, '.env.example')) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=/', $line, $m) === 1) {
                $names[] = $m[1];
            }

            if (count($names) >= self::MAX_ENV_HINTS) {
                break;
            }
        }

        return array_values(array_unique($names));
    }
}
