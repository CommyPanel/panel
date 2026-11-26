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
        $buildSsr = $ssr && $project->hasSsrBuild();

        // Some builds run `php artisan` themselves (Laravel Wayfinder generates its route files when Vite starts),
        // so their asset stage is the PHP image with Node added instead of the light Node image.
        $phpAssets = $assets && $project->assetBuildNeedsPhp($buildSsr);
        $devVendor = $phpAssets && $project->assetBuildNeedsDevVendor($buildSsr);

        $dockerfile = <<<DOCKER
        FROM composer:2 AS vendor
        WORKDIR /app
        COPY {$lockCopy} ./
        RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
        COPY . .
        RUN composer dump-autoload --optimize --no-dev --no-scripts --ignore-platform-reqs


        DOCKER;

        if ($devVendor) {
            $dockerfile .= <<<DOCKER
            FROM composer:2 AS vendor-dev
            WORKDIR /app
            COPY {$lockCopy} ./
            RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
            COPY . .
            RUN composer dump-autoload --optimize --no-scripts --ignore-platform-reqs


            DOCKER;
        }

        // The PHP image with nginx and the extensions: the runtime image is built on it, and so is the asset stage
        // that needs PHP, so the slow extension layers are built once.
        $installer = LaravelRuntime::EXTENSION_INSTALLER;
        $dockerfile .= <<<DOCKER
        FROM {$image} AS base
        COPY --from={$installer} /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
        RUN apt-get update && apt-get install -y --no-install-recommends nginx && rm -rf /var/lib/apt/lists/* \\
            && install-php-extensions {$exts} \\
            && cp "\$PHP_INI_DIR/php.ini-production" "\$PHP_INI_DIR/php.ini"


        DOCKER;

        if ($assets) {
            $dockerfile .= $phpAssets
                ? $this->phpAssetsStage($path, $manager, $node, $buildSsr, $devVendor ? 'vendor-dev' : 'vendor')
                : $this->assetsStage($path, $manager, $node, $buildSsr);
        }

        $dockerfile .= <<<DOCKER
        FROM base
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

            if ($buildSsr) {
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

        if ($phpAssets) {
            $notes[] = "Building front-end assets with {$manager} (Node {$node}) on the PHP image, because the build runs PHP"
                .($devVendor ? ' and uses development packages.' : '.');
        } elseif ($assets) {
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
            ".git\n.env\n.env.*\nnode_modules\n.pnpm-store\nvendor\nstorage/logs/*\nstorage/framework/cache/*\nstorage/framework/sessions/*\nstorage/framework/views/*\nbootstrap/cache/*.php\npublic/hot\npublic/storage\n",
            $notes,
            LaravelRuntime::files(),
        );
    }

    /**
     * The pieces of an asset stage: the COPY lines of the install layer, the install command, the corepack setup and the build command.
     *
     * @return array{string, string, string, string}
     */
    private function assetCommands(string $path, string $manager, bool $ssr): array
    {
        $lock = match ($manager) {
            'pnpm' => 'pnpm-lock.yaml',
            'yarn' => 'yarn.lock',
            default => 'package-lock.json',
        };
        $locked = SourceTree::has($path, $lock);
        $install = match ($manager) {
            'pnpm' => $locked ? 'pnpm install --frozen-lockfile' : 'pnpm install',
            'yarn' => $locked ? 'yarn install --frozen-lockfile' : 'yarn install',
            default => $locked ? 'npm ci' : 'npm install',
        };
        $setup = in_array($manager, ['pnpm', 'yarn'], true) ? "RUN corepack enable\n" : '';
        $run = $manager === 'npm' ? 'npm run' : $manager;

        return [NodeInstall::copyLines($path, $manager), $install, $setup, $ssr ? "{$run} build:ssr" : "{$run} build"];
    }

    /** The Node stage that builds Vite (or Mix) assets: the dependencies are installed from the lock file when there is one. */
    private function assetsStage(string $path, string $manager, int $node, bool $ssr): string
    {
        [$copy, $install, $setup, $build] = $this->assetCommands($path, $manager, $ssr);

        return <<<DOCKER
        FROM node:{$node}-slim AS assets
        WORKDIR /app
        {$setup}{$copy}RUN {$install}
        COPY . .
        COPY --from=vendor /app/vendor ./vendor
        RUN {$build}


        DOCKER;
    }

    /**
     * The asset stage for a build that runs PHP: the PHP image (with its extensions) plus Node copied from the Node image,
     * without replacing anything of PHP's. The application boots there without an .env, a key or a database, so the
     * stage gets harmless settings; they live only in this stage, which never becomes part of the image.
     */
    private function phpAssetsStage(string $path, string $manager, int $node, bool $ssr, string $vendor): string
    {
        [$copy, $install, $setup, $build] = $this->assetCommands($path, $manager, $ssr);
        $key = 'base64:'.base64_encode(str_repeat("\0", 32));

        return <<<DOCKER
        FROM base AS assets
        COPY --from=node:{$node}-slim /usr/local/bin/node /usr/local/bin/node
        COPY --from=node:{$node}-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
        RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \\
            && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \\
            && ln -s ../lib/node_modules/corepack/dist/corepack.js /usr/local/bin/corepack
        WORKDIR /app
        ENV APP_ENV=production APP_KEY={$key} DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync LOG_CHANNEL=stderr
        {$setup}{$copy}RUN {$install}
        COPY . .
        COPY --from={$vendor} /app/vendor ./vendor
        RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && {$build}


        DOCKER;
    }
}
