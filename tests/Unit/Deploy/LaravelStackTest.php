<?php

use App\Deploy\Stacks\DetectionFailed;
use App\Deploy\Stacks\Laravel\ComposerConstraint;
use App\Deploy\Stacks\Laravel\LaravelRuntime;
use App\Deploy\Stacks\StackDetector;
use App\Enums\Stack;

require_once __DIR__.'/SourceHelpers.php';

/**
 * @param  array<string, string|null>  $over  files to add or replace (null removes one)
 * @return array<string, string>
 */
function laravelFiles(array $over = [], string $php = '^8.3'): array
{
    $files = [
        'artisan' => "#!/usr/bin/env php\n<?php\n",
        'composer.json' => json_encode(['require' => ['php' => $php, 'laravel/framework' => '^12.0']]),
        'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v12.3.0', 'require' => ['ext-mbstring' => '*', 'ext-gd' => '*', 'ext-fileinfo' => '*']]]]),
        'bootstrap/app.php' => "<?php\nreturn Application::configure(basePath: dirname(__DIR__))->withRouting(web: 'x', health: '/up')->create();\n",
        'package.json' => json_encode(['scripts' => ['build' => 'vite build'], 'devDependencies' => ['vite' => '^6']]),
        'package-lock.json' => '{}',
    ];

    foreach ($over as $path => $contents) {
        if ($contents === null) {
            unset($files[$path]);
        } else {
            $files[$path] = $contents;
        }
    }

    return $files;
}

function laravelPlan(array $over = [], int $port = 8080, array $options = [], string $php = '^8.3')
{
    return (new StackDetector)->plan(sourceTree(laravelFiles($over, $php)), $port, $options);
}

describe('detection', function () {
    it('is Laravel when composer.json requires the framework and artisan exists, before Node', function () {
        expect(laravelPlan()->stack)->toBe(Stack::Laravel)
            ->and(laravelPlan(['package.json' => json_encode(['scripts' => ['start' => 'x']])])->stack)->toBe(Stack::Laravel);
    });

    it('is not Laravel without artisan, or when composer.json does not need the framework', function () {
        expect(fn () => laravelPlan(['artisan' => null, 'package.json' => null]))->toThrow(DetectionFailed::class, 'Could not detect');
        expect(fn () => (new StackDetector)->plan(sourceTree(['artisan' => '<?php', 'composer.json' => json_encode(['require' => ['symfony/console' => '^7']])]), 8080))
            ->toThrow(DetectionFailed::class, 'Could not detect');
    });

    it('lets a repository Dockerfile win, as for every stack', function () {
        $plan = laravelPlan(['Dockerfile' => 'FROM scratch']);

        expect($plan->stack)->toBe(Stack::Dockerfile)->and($plan->dockerfile)->toBeNull();
    });

    it('names Laravel when it cannot detect anything', function () {
        expect(fn () => (new StackDetector)->plan(sourceTree(['README.md' => 'hi']), 8080))->toThrow(DetectionFailed::class, 'Laravel');
    });

    it('refuses a composer.json that is not JSON', function () {
        expect(fn () => (new StackDetector)->plan(sourceTree(['artisan' => '<?php', 'composer.json' => '{not json']), 8080))->toThrow(DetectionFailed::class, 'Could not detect');
    });
});

describe('the image', function () {
    it('builds on the official php-fpm image with nginx beside it, runs as www-data and listens on the given port', function () {
        $plan = laravelPlan(port: 9000);

        expect($plan->dockerfile)
            ->toContain('FROM php:8.4-fpm-bookworm')
            ->toContain('apt-get install -y --no-install-recommends nginx')
            ->toContain('COPY --from='.LaravelRuntime::EXTENSION_INSTALLER.' /usr/bin/install-php-extensions')
            ->toContain('ENV PORT=9000')
            ->toContain('EXPOSE 9000')
            ->toContain('USER www-data')
            ->toContain('STOPSIGNAL SIGTERM')
            ->toContain('ENTRYPOINT ["commypanel-entrypoint"]')
            ->toContain('composer install --no-dev --no-scripts')
            ->toContain('composer dump-autoload --optimize --no-dev')
            ->not->toContain('CMD ')
            ->not->toContain('frankenphp')
            ->and($plan->notes[0])->toContain('Laravel 12')->toContain('PHP 8.4')->toContain('nginx and PHP-FPM');
    });

    it('keeps secrets and compiled caches out of the image, and does not cache config at build', function () {
        $dockerfile = laravelPlan()->dockerfile;

        expect($dockerfile)->toContain('rm -f .env bootstrap/cache/*.php')
            ->not->toContain('config:cache')
            ->not->toContain('APP_KEY')
            ->not->toContain('ARG ');
    });

    it('picks the PHP version from composer.json, newest default first', function (string $constraint, string $expected) {
        expect(laravelPlan(php: $constraint)->dockerfile)->toContain("FROM php:{$expected}-fpm-bookworm");
    })->with([
        ['^8.3', '8.4'],
        ['^8.2', '8.4'],
        ['~8.3.0', '8.3'],
        ['>=8.2 <8.4', '8.3'],
        ['8.2.*', '8.2'],
        ['^8.2 || ^8.3', '8.4'],
        ['>=8.5', '8.5'],
    ]);

    it('refuses a PHP version CommyPanel has no image for', function () {
        expect(fn () => laravelPlan(php: '^7.4'))->toThrow(DetectionFailed::class, 'asks for PHP ^7.4');
    });

    it('lets the app choose the version, and ignores one that does not exist', function () {
        expect(laravelPlan(options: ['php' => '8.5'])->dockerfile)->toContain('FROM php:8.5-fpm-bookworm')
            ->and(laravelPlan(options: ['php' => '7.4'])->dockerfile)->toContain('FROM php:8.4-fpm-bookworm')
            ->and(laravelPlan(options: ['php' => 'latest; rm -rf /'])->dockerfile)->toContain('FROM php:8.4-fpm-bookworm');
    });

    it('installs the usual extensions and what the project and its packages ask for, never a built-in or odd name', function () {
        $plan = laravelPlan([
            'composer.json' => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^12.0', 'ext-imagick' => '*', 'ext-mbstring' => '*', 'ext-zend-opcache' => '*', 'ext-bad name;x' => '*']]),
            'composer.lock' => json_encode(['packages' => [['name' => 'a/b', 'version' => '1', 'require' => ['ext-soap' => '*', 'ext-gd' => '*']]]]),
        ]);

        expect($plan->dockerfile)->toContain('install-php-extensions bcmath exif gd imagick intl opcache pcntl pdo_mysql pdo_pgsql redis soap zip')
            ->not->toContain('mbstring ')
            ->not->toContain('bad name');
    });

    it('works without a lock file', function () {
        $plan = laravelPlan(['composer.lock' => null]);

        expect($plan->dockerfile)->toContain('COPY composer.json ./')->not->toContain('composer.lock');
    });
});

describe('the front-end build', function () {
    it('builds assets with npm and the lock file, and copies the result', function () {
        $dockerfile = laravelPlan()->dockerfile;

        expect($dockerfile)->toContain('FROM node:22-slim AS assets')
            ->toContain('COPY package.json package-lock.json ./')
            ->toContain('RUN npm ci')
            ->toContain('COPY --from=vendor /app/vendor ./vendor')
            ->toContain('RUN npm run build')
            ->toContain('COPY --from=assets --chown=www-data:www-data /app/public /app/public');
    });

    it('uses pnpm or yarn when the project does, with the frozen lock file', function () {
        $pnpm = laravelPlan(['package-lock.json' => null, 'pnpm-lock.yaml' => 'lockfileVersion: 9'])->dockerfile;
        $yarn = laravelPlan(['package-lock.json' => null, 'yarn.lock' => '# yarn'])->dockerfile;
        $declared = laravelPlan(['package.json' => json_encode(['packageManager' => 'pnpm@9.1.0', 'scripts' => ['build' => 'vite build']]), 'package-lock.json' => null])->dockerfile;

        expect($pnpm)->toContain('RUN corepack enable')->toContain('pnpm install --frozen-lockfile')->toContain('RUN pnpm build')
            ->and($yarn)->toContain('yarn install --frozen-lockfile')->toContain('RUN yarn build')
            ->and($declared)->toContain('RUN pnpm build')->toContain('RUN pnpm install')->not->toContain('--frozen-lockfile');
    });

    it('lets the app choose the manager and the Node version', function () {
        $dockerfile = laravelPlan(options: ['node_manager' => 'pnpm', 'node' => 20])->dockerfile;

        expect($dockerfile)->toContain('FROM node:20-slim AS assets')->toContain('RUN pnpm build');
    });

    it('skips Node when told to, when there is no package.json, or no build script', function (array $over, array $options) {
        $plan = laravelPlan($over, options: $options);

        expect($plan->dockerfile)->not->toContain('AS assets')->not->toContain('COPY --from=assets');
    })->with([
        'chosen none' => [[], ['node_manager' => 'none']],
        'no package.json' => [['package.json' => null, 'package-lock.json' => null], []],
        'no build script' => [['package.json' => json_encode(['scripts' => ['dev' => 'vite']])], []],
    ]);

    it('says in the build log what it did about the assets', function () {
        expect(laravelPlan()->notes)->toContain('Building front-end assets with npm (Node 22).')
            ->and(laravelPlan(['package.json' => json_encode(['scripts' => ['dev' => 'vite']])])->notes)->toContain('package.json has no build script, so no front-end build runs.');
    });

    it('ships the SSR bundle when the build script already builds it', function () {
        $plan = laravelPlan(['package.json' => json_encode(['scripts' => ['build' => 'vite build && vite build --ssr']])], options: ['ssr' => true]);

        expect($plan->dockerfile)->toContain('RUN npm run build')
            ->not->toContain('build:ssr')
            ->toContain('COPY --from=assets --chown=www-data:www-data /app/bootstrap/ssr /app/bootstrap/ssr')
            ->toContain('COPY --from=assets /usr/local/bin/node /usr/local/bin/node');
    });

    it('builds the SSR bundle and keeps Node in the image when SSR is on', function () {
        $plan = laravelPlan(['package.json' => json_encode(['scripts' => ['build' => 'vite build', 'build:ssr' => 'vite build && vite build --ssr']])], options: ['ssr' => true]);

        expect($plan->dockerfile)->toContain('RUN npm run build:ssr')
            ->toContain('COPY --from=assets --chown=www-data:www-data /app/bootstrap/ssr /app/bootstrap/ssr')
            ->toContain('COPY --from=assets --chown=www-data:www-data /app/node_modules /app/node_modules')
            ->toContain('COPY --from=assets /usr/local/bin/node /usr/local/bin/node');

        // Without the option, or without an SSR build script, the plain build runs and the image has no Node.
        expect(laravelPlan()->dockerfile)->not->toContain('/usr/local/bin/node')
            ->and(laravelPlan(options: ['ssr' => true])->dockerfile)->not->toContain('/usr/local/bin/node');
    });
});

describe('the install layer', function () {
    /** The Dockerfile lines between the asset stage's start and the build, as written. */
    $installLines = fn (string $dockerfile): string => (string) (preg_match('/FROM [^\n]+ AS assets\n(.*?)\nRUN [^\n]*build/s', $dockerfile, $m) === 1 ? $m[1] : '');

    it('copies pnpm\'s settings with the lock file, because they change what the lock file means', function () {
        $dockerfile = laravelPlan([
            'package-lock.json' => null,
            'pnpm-lock.yaml' => 'lockfileVersion: 9',
            'pnpm-workspace.yaml' => "overrides:\n  picocolors: 1.1.1\n",
            '.npmrc' => 'ignore-scripts=true',
            '.pnpmfile.cjs' => 'module.exports = {};',
            'patches/pkg@1.0.0.patch' => 'diff',
        ])->dockerfile;

        expect($dockerfile)->toContain("COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc .pnpmfile.cjs ./\nCOPY patches ./patches\nRUN pnpm install --frozen-lockfile");

        // Settings first, the rest of the code after: the install layer stays cached until one of them changes.
        expect(strpos($dockerfile, 'RUN pnpm install --frozen-lockfile'))->toBeLessThan(strpos($dockerfile, "COPY . .\nCOPY --from=vendor /app/vendor ./vendor"));
    });

    it('copies only what exists', function () {
        $dockerfile = laravelPlan(['package-lock.json' => null, 'pnpm-lock.yaml' => 'lockfileVersion: 9'])->dockerfile;

        expect($dockerfile)->toContain("COPY package.json pnpm-lock.yaml ./\n")->not->toContain('pnpm-workspace.yaml')->not->toContain('.pnpmfile.cjs')->not->toContain('COPY patches');
    });

    it('copies .npmrc for npm', function () {
        expect(laravelPlan(['.npmrc' => 'registry=https://registry.example.com/'])->dockerfile)->toContain("COPY package.json package-lock.json .npmrc ./\nRUN npm ci");
    });

    it('copies Yarn\'s settings, releases and plugins, but not its cache', function () {
        $dockerfile = laravelPlan([
            'package-lock.json' => null,
            'yarn.lock' => '# yarn',
            '.npmrc' => 'x=y',
            '.yarnrc.yml' => 'nodeLinker: node-modules',
            '.yarn/releases/yarn-4.0.0.cjs' => '//',
            '.yarn/plugins/plugin.cjs' => '//',
            '.yarn/cache/big.zip' => 'zip',
        ])->dockerfile;

        expect($dockerfile)->toContain("COPY package.json yarn.lock .npmrc .yarnrc.yml ./\nCOPY .yarn/releases ./.yarn/releases\nCOPY .yarn/plugins ./.yarn/plugins\nRUN yarn install --frozen-lockfile")
            ->not->toContain('.yarn/cache');
    });

    it('keeps the package manager\'s store out of the build context', function () {
        expect(laravelPlan()->dockerignore)->toContain(".pnpm-store\n");
    });
});

describe('a front-end build that runs PHP', function () {
    $wayfinderPackage = fn (string $section = 'devDependencies') => json_encode(['scripts' => ['build' => 'vite build'], $section => ['vite' => '^7', '@laravel/vite-plugin-wayfinder' => '^0.1']]);

    /** The Dockerfile from the last FROM on: the image that is shipped. */
    $final = fn (string $dockerfile): string => substr($dockerfile, (int) strrpos($dockerfile, "\nFROM ") + 1);

    it('is the PHP image with Node added, built on the same base as the runtime image', function () use ($wayfinderPackage, $final) {
        $dockerfile = laravelPlan(['package.json' => $wayfinderPackage()])->dockerfile;

        expect($dockerfile)
            ->toContain("FROM php:8.4-fpm-bookworm AS base\n")
            ->toContain("FROM base AS assets\n")
            ->toContain('COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/node')
            ->toContain('COPY --from=node:22-slim /usr/local/lib/node_modules /usr/local/lib/node_modules')
            ->toContain('ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm')
            ->toContain('ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx')
            ->toContain('ln -s ../lib/node_modules/corepack/dist/corepack.js /usr/local/bin/corepack')
            ->toContain('COPY --from=vendor /app/vendor ./vendor')
            ->toContain('npm run build')
            ->not->toContain('FROM node:')
            ->and(substr_count($dockerfile, 'install-php-extensions bcmath'))->toBe(1)
            ->and(substr_count($dockerfile, 'AS base'))->toBe(1)
            ->and($final($dockerfile))->toStartWith("FROM base\nENV PORT=8080");
    });

    it('lets the application boot without an .env, a key or a database, with settings that exist only in that stage', function () use ($wayfinderPackage, $final) {
        $dockerfile = laravelPlan(['package.json' => $wayfinderPackage()])->dockerfile;

        expect($dockerfile)->toContain('ENV APP_ENV=production APP_KEY=base64:'.base64_encode(str_repeat("\0", 32)).' DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync LOG_CHANNEL=stderr')
            ->toContain('mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && npm run build')
            // Nothing of it is in the shipped image.
            ->and($final($dockerfile))->not->toContain('APP_KEY')->not->toContain('DB_DATABASE')->not->toContain('node:22-slim')
            ->and($final($dockerfile))->toContain('COPY --from=assets --chown=www-data:www-data /app/public /app/public');
    });

    it('is chosen when package.json lists a Wayfinder plugin, wherever it is listed', function (string $section, string $name) {
        $package = json_encode(['scripts' => ['build' => 'vite build'], $section => [$name => '^1']]);

        expect(laravelPlan(['package.json' => $package])->dockerfile)->toContain('FROM base AS assets');
    })->with([
        ['devDependencies', '@laravel/vite-plugin-wayfinder'],
        ['dependencies', '@laravel/vite-plugin-wayfinder'],
        ['optionalDependencies', '@laravel/vite-plugin-wayfinder'],
        ['devDependencies', 'vite-plugin-wayfinder-extra'],
        ['devDependencies', 'vite-plugin-run'],
    ]);

    it('is chosen when the build runs artisan or php, directly or through the scripts it runs', function (array $scripts) {
        $package = json_encode(['scripts' => $scripts, 'devDependencies' => ['vite' => '^7']]);

        expect(laravelPlan(['package.json' => $package])->dockerfile)->toContain('FROM base AS assets');
    })->with([
        'directly' => [['build' => 'php artisan wayfinder:generate && vite build']],
        'artisan alone' => [['build' => './artisan ziggy:generate && vite build']],
        'through npm run' => [['build' => 'npm run types && vite build', 'types' => 'php artisan wayfinder:generate']],
        'through pnpm' => [['build' => 'pnpm types && vite build', 'types' => 'php artisan x']],
        'two levels deep' => [['build' => 'npm run a', 'a' => 'yarn b', 'b' => 'php artisan x']],
        'a pre script' => [['build' => 'vite build', 'prebuild' => 'php artisan x']],
        'a post script' => [['build' => 'vite build', 'postbuild' => 'php artisan x']],
    ]);

    it('is chosen when the Vite config calls artisan or Wayfinder', function (string $file, string $contents) {
        $package = json_encode(['scripts' => ['build' => 'vite build'], 'devDependencies' => ['vite' => '^7']]);

        expect(laravelPlan(['package.json' => $package, $file => $contents])->dockerfile)->toContain('FROM base AS assets');
    })->with([
        ['vite.config.js', "import { run } from 'vite-plugin-run'; run([{ run: ['php', 'artisan', 'x'] }])"],
        ['vite.config.ts', "import { wayfinder } from './w'; plugins: [wayfinder()]"],
        ['vite.config.mjs', 'exec("php artisan x")'],
    ]);

    it('is not chosen for an ordinary build, which keeps the light Node stage', function () {
        $dockerfile = laravelPlan(['package.json' => json_encode(['scripts' => ['build' => 'vite build', 'dev' => 'php artisan serve'], 'devDependencies' => ['vite' => '^7', 'laravel-vite-plugin' => '^2']]), 'vite.config.js' => "import laravel from 'laravel-vite-plugin';"])->dockerfile;

        expect($dockerfile)->toContain("FROM node:22-slim AS assets\nWORKDIR /app\nCOPY package.json package-lock.json ./\nRUN npm ci\nCOPY . .\nCOPY --from=vendor /app/vendor ./vendor\nRUN npm run build\n")
            ->not->toContain('FROM base AS assets')
            ->not->toContain('APP_KEY')
            ->not->toContain('vendor-dev');
    });

    it('uses the package manager and Node version the app chose', function () use ($wayfinderPackage) {
        $dockerfile = laravelPlan(['package.json' => $wayfinderPackage(), 'package-lock.json' => null, 'pnpm-lock.yaml' => 'x', 'pnpm-workspace.yaml' => 'overrides: {}'], options: ['node' => 20])->dockerfile;

        expect($dockerfile)->toContain('COPY --from=node:20-slim /usr/local/bin/node')
            ->toContain("RUN corepack enable\nCOPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./\nRUN pnpm install --frozen-lockfile")
            ->toContain('&& pnpm build');
    });

    it('runs the SSR build through the same stage', function () {
        $package = json_encode(['scripts' => ['build' => 'vite build', 'build:ssr' => 'vite build && vite build --ssr'], 'devDependencies' => ['@laravel/vite-plugin-wayfinder' => '^0.1']]);
        $dockerfile = laravelPlan(['package.json' => $package], options: ['ssr' => true])->dockerfile;

        expect($dockerfile)->toContain('FROM base AS assets')
            ->toContain('&& npm run build:ssr')
            ->toContain('COPY --from=assets --chown=www-data:www-data /app/bootstrap/ssr /app/bootstrap/ssr');
    });

    it('does not apply when the person turned the front-end build off', function () use ($wayfinderPackage) {
        $dockerfile = laravelPlan(['package.json' => $wayfinderPackage()], options: ['node_manager' => 'none'])->dockerfile;

        expect($dockerfile)->not->toContain('AS assets')->not->toContain('node:22-slim');
    });

    it('says in the build log that the assets are built on the PHP image', function () use ($wayfinderPackage) {
        expect(laravelPlan(['package.json' => $wayfinderPackage()])->notes)->toContain('Building front-end assets with npm (Node 22) on the PHP image, because the build runs PHP.');
    });
});

describe('the PHP packages a front-end build needs', function () {
    $package = json_encode(['scripts' => ['build' => 'vite build'], 'devDependencies' => ['@laravel/vite-plugin-wayfinder' => '^0.1']]);
    $composer = fn (array $require = [], array $dev = []) => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^12.0', ...$require], 'require-dev' => $dev]);

    it('installs the development packages for the asset stage when Wayfinder is only a development package', function () use ($package, $composer) {
        $plan = laravelPlan(['package.json' => $package, 'composer.json' => $composer([], ['laravel/wayfinder' => '^0.1'])]);
        $dockerfile = $plan->dockerfile;

        expect($dockerfile)->toContain("FROM composer:2 AS vendor-dev\n")
            ->toContain('RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs')
            ->toContain('RUN composer dump-autoload --optimize --no-scripts --ignore-platform-reqs')
            ->toContain('COPY --from=vendor-dev /app/vendor ./vendor')
            ->and(substr_count($dockerfile, '--no-dev'))->toBe(2); // the production vendor's install and autoload dump

        // The shipped image still gets the production vendor.
        expect(substr($dockerfile, (int) strrpos($dockerfile, "\nFROM ")))->toContain('COPY --from=vendor --chown=www-data:www-data /app /app')->not->toContain('vendor-dev');
        expect($plan->notes)->toContain('Building front-end assets with npm (Node 22) on the PHP image, because the build runs PHP and uses development packages.');
    });

    it('needs no second install when Wayfinder is a normal package', function () use ($package, $composer) {
        $dockerfile = laravelPlan(['package.json' => $package, 'composer.json' => $composer(['laravel/wayfinder' => '^0.1'], ['phpunit/phpunit' => '^11'])])->dockerfile;

        expect($dockerfile)->not->toContain('vendor-dev')->toContain('COPY --from=vendor /app/vendor ./vendor');
    });

    it('installs them when the build calls artisan and the project has development packages, since a command may come from one', function () use ($composer) {
        $package = json_encode(['scripts' => ['build' => 'php artisan x && vite build'], 'devDependencies' => ['vite' => '^7']]);
        $with = laravelPlan(['package.json' => $package, 'composer.json' => $composer([], ['barryvdh/laravel-ide-helper' => '^3'])])->dockerfile;
        $without = laravelPlan(['package.json' => $package, 'composer.json' => $composer()])->dockerfile;

        expect($with)->toContain('FROM composer:2 AS vendor-dev')->and($without)->not->toContain('vendor-dev')->toContain('FROM base AS assets');
    });

    it('installs nothing extra for a build that does not run PHP, whatever composer.json says', function () use ($composer) {
        $package = json_encode(['scripts' => ['build' => 'vite build']]);

        expect(laravelPlan(['package.json' => $package, 'composer.json' => $composer([], ['laravel/wayfinder' => '^0.1'])])->dockerfile)->not->toContain('vendor-dev');
    });
});

describe('Octane', function () {
    it('is not run: the build says the app is served with PHP-FPM and what to do to run Octane', function () {
        $plan = laravelPlan(['composer.json' => '{"require":{"php":"^8.3","laravel/framework":"^12.0","laravel/octane":"^2.0"}}'], options: ['octane' => true]);

        expect($plan->notes)->toContain('laravel/octane is installed, but this image serves the app with PHP-FPM. To run Octane, add a Dockerfile to the repository.')
            ->and($plan->dockerfile)->not->toContain('octane:start');
    });

    it('says nothing about Octane when it is not installed', function () {
        expect(implode(' ', laravelPlan()->notes))->not->toContain('octane');
    });
});

describe('the files next to the Dockerfile', function () {
    it('brings nginx and PHP-FPM settings, PHP settings and an entrypoint, and the Dockerfile copies exactly those', function () {
        $plan = laravelPlan();

        expect(array_keys($plan->files))->toBe(['.commypanel/nginx.conf', '.commypanel/php-fpm.conf', '.commypanel/php.ini', '.commypanel/entrypoint.sh']);

        foreach (array_keys($plan->files) as $file) {
            expect($plan->dockerfile)->toContain("COPY {$file} ");
        }

        expect($plan->dockerignore)->toContain('.env')->toContain('vendor')->toContain('node_modules')->not->toContain('.commypanel');
    });

    it('lets only index.php run PHP, so an uploaded .php file is never executed, and hides dotfiles', function () {
        $nginx = LaravelRuntime::nginx();

        expect($nginx)->toContain('location = /index.php {')
            ->toContain('fastcgi_param SCRIPT_FILENAME $document_root/index.php;')
            ->toContain('location ~ \.php(/|$) {')
            ->toContain('location ~ /\.(?!well-known(/|$)) {')
            ->toContain('try_files $uri $uri/ /index.php?$query_string;')
            ->toContain('root /app/public;');

        // The PHP block comes before the catch-all and nothing else is handed to FPM.
        expect(substr_count($nginx, 'fastcgi_pass'))->toBe(1);
    });

    it('shows nginx\'s https and the real client address to the app, believing the headers from private addresses only', function () {
        $nginx = LaravelRuntime::nginx();

        expect($nginx)->toContain('real_ip_header X-Real-IP;')
            ->toContain('set_real_ip_from 10.0.0.0/8;')
            ->toContain('geo $realip_remote_addr $commypanel_private')
            ->toContain('map "$commypanel_private:$http_x_forwarded_proto" $commypanel_https')
            ->toContain('"1:https" on;')
            ->toContain('default off;')
            ->toContain('fastcgi_param HTTPS $commypanel_https;')
            // Never a blanket trust.
            ->not->toContain('set_real_ip_from 0.0.0.0/0')
            ->not->toContain('set_real_ip_from ::/0');
    });

    it('runs nginx as a non-root user: everything it writes is under /tmp and its errors go to stderr', function () {
        $nginx = LaravelRuntime::nginx();

        expect($nginx)->toContain('pid /tmp/nginx.pid;')
            ->toContain('error_log stderr warn;')
            ->toContain('access_log /dev/stdout;')
            ->toContain('client_body_temp_path /tmp/nginx/client_body;')
            ->toContain('fastcgi_temp_path /tmp/nginx/fastcgi;')
            ->toContain('include /tmp/nginx/server.conf;')
            ->toContain('fastcgi_pass unix:/tmp/php-fpm.sock;')
            ->toContain('server_tokens off;')
            ->toContain('gzip on;')
            ->toContain('location /build/ {')
            ->toContain('expires 1y;')
            ->toContain('immutable');
    });

    it('configures PHP-FPM so the environment reaches PHP, with settings that follow the environment', function () {
        $fpm = LaravelRuntime::fpm();

        expect($fpm)->toContain('clear_env = no')
            ->toContain('catch_workers_output = yes')
            ->toContain('listen = /tmp/php-fpm.sock')
            ->toContain('pid = /tmp/php-fpm.pid')
            ->toContain('error_log = /proc/self/fd/2')
            ->toContain('pm = dynamic')
            ->toContain('pm.max_children = ${PHP_FPM_PM_MAX_CHILDREN}')
            ->toContain('pm.max_requests = ${PHP_FPM_PM_MAX_REQUESTS}')
            // Without a timeout PHP-FPM cuts running requests off the moment it is told to stop.
            ->toContain('process_control_timeout = ${PHP_FPM_PROCESS_CONTROL_TIMEOUT}')
            ->toContain('php_admin_value[memory_limit] = ${PHP_MEMORY_LIMIT}');
    });

    it('has an entrypoint that rebuilds what an empty storage volume hides, then caches with the real environment', function () {
        $script = LaravelRuntime::entrypoint();

        expect($script)->toContain('mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache')
            ->toContain('php artisan storage:link')
            ->toContain('php artisan optimize')
            ->toContain('COMMYPANEL_OPTIMIZE:-true');
    });

    it('runs any other command as it is, with the same preparation, and the web server when there is none', function () {
        $script = LaravelRuntime::entrypoint();

        // The preparation comes first, then a command is exec\'d, and only then the servers are set up.
        expect(strpos($script, 'php artisan optimize'))->toBeLessThan(strpos($script, 'exec "$@"'))
            ->and(strpos($script, 'exec "$@"'))->toBeLessThan(strpos($script, 'php-fpm -F'))
            ->and($script)->toContain('if [ "$#" -gt 0 ]; then');
    });

    it('starts php-fpm and nginx in the foreground, stops both gracefully on a stop signal and fails when one dies', function () {
        $script = LaravelRuntime::entrypoint();

        expect($script)->toContain('php-fpm -F -y /usr/local/etc/php-fpm.conf &')
            ->toContain("nginx -e /tmp/nginx-start.log -c /etc/nginx/commypanel.conf -g 'daemon off;' &")
            ->toContain('trap \'stopping=1; kill -QUIT "$web" "$fpm" 2>/dev/null || true\' TERM INT QUIT')
            ->toContain('wait -n "$fpm" "$web"')
            ->toContain('exit "$code"')
            ->toContain('exit $(( f != 0 ? f : w ))');
    });

    it('takes its settings from the environment, with safe defaults', function () {
        $script = LaravelRuntime::entrypoint();

        expect($script)->toContain('NGINX_CLIENT_MAX_BODY_SIZE:-100m')
            ->toContain('PORT:-8080')
            ->toContain('PHP_FPM_PM_MAX_CHILDREN:-10')
            ->toContain('PHP_FPM_PM_MAX_REQUESTS:-500')
            ->toContain('PHP_FPM_PROCESS_CONTROL_TIMEOUT:-8s')
            ->toContain('PHP_MEMORY_LIMIT:-256M')
            ->toContain('PHP_UPLOAD_MAX_FILESIZE:-100M')
            ->toContain('PHP_POST_MAX_SIZE:-100M')
            // A malformed size must not become part of the nginx config.
            ->toContain("grep -Eq '^[0-9]+[kKmMgG]?\$'");
    });

    it('parses as shell scripts', function () {
        $path = sys_get_temp_dir().'/cp-entrypoint-'.bin2hex(random_bytes(4)).'.sh';
        file_put_contents($path, LaravelRuntime::entrypoint());
        exec('bash -n '.escapeshellarg($path).' 2>&1', $out, $code);
        unlink($path);

        expect($code)->toBe(0, implode("\n", $out));
    });

    it('never puts anything secret in them', function () {
        foreach (LaravelRuntime::files() as $contents) {
            expect($contents)->not->toMatch('/APP_KEY|\bpassword\b|\bsecret\b|\btoken\b/i');
        }
    });

    it('sets upload and memory limits, and PHP errors do not reach the visitor', function () {
        expect(LaravelRuntime::phpIni())->toContain('upload_max_filesize = 100M')->toContain('post_max_size = 100M')->toContain('memory_limit = 256M')
            ->toContain('display_errors = Off')->toContain('opcache.validate_timestamps = 0')->toContain('expose_php = Off');
    });

    it('is built on the php-fpm image for the PHP minor, patch release following', function () {
        expect(LaravelRuntime::image('8.3'))->toBe('php:8.3-fpm-bookworm');
    });
});

describe('composer constraints', function () {
    it('tells which PHP minors a constraint accepts', function (string $constraint, string $minor, bool $expected) {
        expect(ComposerConstraint::acceptsMinor($constraint, $minor))->toBe($expected);
    })->with([
        ['^8.3', '8.2', false], ['^8.3', '8.4', true], ['~8.3.0', '8.3', true], ['~8.3.0', '8.4', false],
        ['>=8.2 <8.5', '8.4', true], ['>=8.2 <8.5', '8.5', false], ['8.3.*', '8.3', true], ['8.3.*', '8.4', false],
        ['^7.4 || ^8.0', '8.4', true], ['^7.4', '8.4', false], ['8.2 - 8.3', '8.3', true], ['8.2 - 8.3', '8.4', false],
        ['<8.4', '8.4', false], ['*', '8.2', true], ['', '8.2', true], ['dev-main', '8.4', true],
    ]);
});
