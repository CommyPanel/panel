<?php

namespace App\Deploy\Stacks;

use App\Deploy\Stacks\Laravel\LaravelProject;
use App\Deploy\Stacks\Laravel\LaravelRuntime;
use App\Enums\Stack;

/**
 * Laravel apps (composer.json requires laravel/framework and there is an artisan file), built into one
 * image with nginx and PHP-FPM: Composer dependencies in one stage, front-end assets in another (only
 * when package.json has a build script), and a runtime stage on the official php-fpm image that runs
 * both servers as www-data (see LaravelRuntime).
 *
 * `$options` are the app's `stack_options`: `php` ("8.2" to "8.5"), `node_manager` (npm, pnpm, yarn or
 * none), `node` (a major version) and `ssr` (build the Inertia SSR bundle and keep Node in the image so
 * `php artisan inertia:start-ssr` can run). Anything else is ignored.
 *
 * The container's other processes (queue worker, scheduler, ...) use the same image with another command
 * (`php artisan ...`; the working directory is the app root and the entrypoint prepares the container the
 * same way). Nothing secret is ever written into the build context: the environment arrives at run time,
 * and config is cached by the entrypoint, not at build.
 */
class LaravelStack implements StackDefinition
{
    public function stack(): Stack
    {
        return Stack::Laravel;
    }

    public function matches(string $path): bool
    {
        return LaravelProject::matches($path);
    }

    public function plan(string $path, int $port, array $options = []): BuildPlan
    {
        $project = new LaravelProject($path);
        $php = $project->phpVersion(is_string($options['php'] ?? null) ? $options['php'] : null);
        $extensions = $project->extensions();
        $ssr = ($options['ssr'] ?? false) === true && $project->hasPackageJson();

        $manager = is_string($options['node_manager'] ?? null) && in_array($options['node_manager'], [...LaravelProject::NODE_MANAGERS, 'none'], true)
            ? $options['node_manager']
            : $project->nodeManager();
        $node = is_int($options['node'] ?? null) && $options['node'] >= 18 && $options['node'] <= 30 ? $options['node'] : $project->nodeMajor();
        $assets = $manager !== 'none' && $project->hasPackageJson() && ($project->hasBuildScript() || ($ssr && $project->hasSsrBuild()));

        $image = LaravelRuntime::image($php);
        $exts = implode(' ', $extensions);
        $lockCopy = SourceTree::has($path, 'composer.lock') ? 'composer.json composer.lock' : 'composer.json';

        $dockerfile = <<<DOCKER
        FROM composer:2 AS vendor
        WORKDIR /app
        COPY {$lockCopy} ./
        RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
        COPY . .
        RUN composer dump-autoload --optimize --no-dev --no-scripts --ignore-platform-reqs


        DOCKER;

        if ($assets) {
            $dockerfile .= $this->assetsStage($path, $manager, $node, $ssr && $project->hasSsrBuild());
        }

        $installer = LaravelRuntime::EXTENSION_INSTALLER;
        $dockerfile .= <<<DOCKER
        FROM {$image}
        COPY --from={$installer} /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
        RUN apt-get update && apt-get install -y --no-install-recommends nginx && rm -rf /var/lib/apt/lists/* \\
            && install-php-extensions {$exts} \\
            && cp "\$PHP_INI_DIR/php.ini-production" "\$PHP_INI_DIR/php.ini"
        ENV PORT={$port}
        WORKDIR /app
        COPY .commypanel/php.ini /usr/local/etc/php/conf.d/zz-commypanel.ini
        COPY .commypanel/php-fpm.conf /usr/local/etc/php-fpm.conf
        COPY .commypanel/nginx.conf /etc/nginx/commypanel.conf
        COPY .commypanel/entrypoint.sh /usr/local/bin/commypanel-entrypoint
        RUN chmod +x /usr/local/bin/commypanel-entrypoint && rm -f /usr/local/etc/php-fpm.d/* && mkdir -p /tmp/nginx && chown -R www-data:www-data /tmp/nginx
        COPY --from=vendor --chown=www-data:www-data /app /app

        DOCKER;

        if ($assets) {
            $dockerfile .= "COPY --from=assets --chown=www-data:www-data /app/public /app/public\n";

            if ($ssr && $project->hasSsrBuild()) {
                $dockerfile .= "COPY --from=assets --chown=www-data:www-data /app/bootstrap/ssr /app/bootstrap/ssr\n";
                $dockerfile .= "COPY --from=assets --chown=www-data:www-data /app/node_modules /app/node_modules\n";
                $dockerfile .= "COPY --from=assets /usr/local/bin/node /usr/local/bin/node\n";
            }
        }

        // A committed .env or compiled caches must not reach the image: the environment is the configuration. The scripts
        // that run after an autoload dump (package discovery, publishing a package's assets) run here, where the code is complete.
        $dockerfile .= <<<DOCKER
        RUN rm -f .env bootstrap/cache/*.php
        COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
        USER www-data
        ENV HOME=/tmp COMPOSER_HOME=/tmp/.composer COMPOSER_CACHE_DIR=/tmp/.composer/cache
        RUN composer run-script post-autoload-dump --no-interaction --ansi || php artisan package:discover --ansi || true
        EXPOSE {$port}
        STOPSIGNAL SIGTERM
        ENTRYPOINT ["commypanel-entrypoint"]

        DOCKER;

        $notes = ["Detected Laravel {$project->laravelMajor()}; building with PHP {$php} (nginx and PHP-FPM)."];

        if ($assets) {
            $notes[] = "Building front-end assets with {$manager} (Node {$node}).";
        } elseif ($project->hasPackageJson() && ! $project->hasBuildScript()) {
            $notes[] = 'package.json has no build script, so no front-end build runs.';
        }

        if ($project->octaneInstalled()) {
            $notes[] = 'laravel/octane is installed, but this image serves the app with PHP-FPM. To run Octane, add a Dockerfile to the repository.';
        }

        return new BuildPlan(
            Stack::Laravel,
            $dockerfile,
            ".git\n.env\n.env.*\nnode_modules\nvendor\nstorage/logs/*\nstorage/framework/cache/*\nstorage/framework/sessions/*\nstorage/framework/views/*\nbootstrap/cache/*.php\npublic/hot\npublic/storage\n",
            $notes,
            LaravelRuntime::files(),
        );
    }

    /** The Node stage that builds Vite (or Mix) assets: the dependencies are installed from the lock file when there is one. */
    private function assetsStage(string $path, string $manager, int $node, bool $ssr): string
    {
        [$copy, $install, $setup] = match ($manager) {
            'pnpm' => [SourceTree::has($path, 'pnpm-lock.yaml') ? 'package.json pnpm-lock.yaml' : 'package.json', SourceTree::has($path, 'pnpm-lock.yaml') ? 'pnpm install --frozen-lockfile' : 'pnpm install', "RUN corepack enable\n"],
            'yarn' => [SourceTree::has($path, 'yarn.lock') ? 'package.json yarn.lock' : 'package.json', SourceTree::has($path, 'yarn.lock') ? 'yarn install --frozen-lockfile' : 'yarn install', "RUN corepack enable\n"],
            default => SourceTree::has($path, 'package-lock.json')
                ? ['package.json package-lock.json', 'npm ci', '']
                : ['package.json', 'npm install', ''],
        };
        $run = $manager === 'npm' ? 'npm run' : $manager;
        $build = $ssr ? "{$run} build:ssr" : "{$run} build";

        return <<<DOCKER
        FROM node:{$node}-slim AS assets
        WORKDIR /app
        {$setup}COPY {$copy} ./
        RUN {$install}
        COPY . .
        COPY --from=vendor /app/vendor ./vendor
        RUN {$build}


        DOCKER;
    }
}
