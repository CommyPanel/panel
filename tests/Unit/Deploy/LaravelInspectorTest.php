<?php

use App\Deploy\Stacks\Laravel\LaravelInspector;

require_once __DIR__.'/SourceHelpers.php';

function inspectLaravel(array $files): array
{
    $base = [
        'artisan' => '<?php',
        'composer.json' => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^12.0']]),
    ];

    return (new LaravelInspector)->inspect(sourceTree([...$base, ...$files]));
}

/** @return array<string, array<string, mixed>> the services by kind */
function servicesOf(array $detected): array
{
    return collect($detected['services'])->keyBy('kind')->all();
}

it('reads the basics from composer and package files', function () {
    $detected = inspectLaravel([
        'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v11.9.0']]]),
        'package.json' => json_encode(['scripts' => ['build' => 'vite build'], 'engines' => ['node' => '>=20']]),
        'pnpm-lock.yaml' => 'x',
        'bootstrap/app.php' => "<?php\n->withRouting(web: __DIR__.'/../routes/web.php', health: '/healthz')\n",
    ]);

    expect($detected['laravel_major'])->toBe(11)
        ->and($detected['php'])->toBe('8.4')
        ->and($detected['php_error'])->toBeNull()
        ->and($detected['has_package_json'])->toBeTrue()
        ->and($detected['has_build_script'])->toBeTrue()
        ->and($detected['node_manager'])->toBe('pnpm')
        ->and($detected['node'])->toBe(20)
        ->and($detected['health_path'])->toBe('/healthz');
});

it('falls back to the framework constraint, a root health path and no Node', function () {
    $detected = inspectLaravel(['composer.json' => json_encode(['require' => ['php' => '^8.2', 'laravel/framework' => '^10.0']])]);

    expect($detected['laravel_major'])->toBe(10)
        ->and($detected['health_path'])->toBe('/')
        ->and($detected['has_package_json'])->toBeFalse()
        ->and($detected['node_manager'])->toBeNull()
        ->and($detected['warnings'])->toBe([]);
});

it('reports a PHP requirement it cannot build instead of failing', function () {
    $detected = inspectLaravel(['composer.json' => json_encode(['require' => ['php' => '^7.4', 'laravel/framework' => '^8.0']])]);

    expect($detected['php'])->toBeNull()->and($detected['php_error'])->toContain('^7.4');
});

describe('services', function () {
    it('always offers the scheduler (on) and a queue worker (off), with the commands to run', function () {
        $services = servicesOf(inspectLaravel([]));

        expect(array_keys($services))->toBe(['scheduler', 'queue'])
            ->and($services['scheduler'])->toMatchArray(['name' => 'scheduler', 'default_command' => 'php artisan schedule:work', 'default_enabled' => true, 'overlap' => 'stop_first', 'supported' => true, 'needs_node' => false])
            ->and($services['queue'])->toMatchArray(['default_command' => 'php artisan queue:work --tries=3 --timeout=90', 'default_enabled' => false, 'overlap' => 'overlap', 'supported' => true]);
    });

    it('finds Horizon in composer.json or the lock file, and says it replaces the plain worker', function () {
        foreach ([
            ['composer.json' => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^12.0', 'laravel/horizon' => '^5.0']])],
            ['composer.lock' => json_encode(['packages' => [['name' => 'laravel/horizon', 'version' => 'v5.30.0']]])],
        ] as $files) {
            $services = servicesOf(inspectLaravel($files));

            expect($services['horizon'])->toMatchArray(['detected_by' => 'laravel/horizon', 'default_command' => 'php artisan horizon', 'default_enabled' => false, 'overlap' => 'stop_first', 'supported' => true])
                ->and($services['queue']['detected_by'])->toContain('Horizon');
        }
    });

    it('finds Pulse', function () {
        $services = servicesOf(inspectLaravel(['composer.lock' => json_encode(['packages' => [['name' => 'laravel/pulse', 'version' => 'v1.4.0']]])]));

        expect($services['pulse'])->toMatchArray(['default_command' => 'php artisan pulse:check', 'overlap' => 'stop_first', 'default_enabled' => false]);
    });

    it('finds Inertia SSR through a build:ssr script or an ssr entry, and says it needs Node and an address', function () {
        $inertia = ['composer.lock' => json_encode(['packages' => [['name' => 'inertiajs/inertia-laravel', 'version' => 'v2.0.0']]])];

        $viaScript = servicesOf(inspectLaravel([...$inertia, 'package.json' => json_encode(['scripts' => ['build:ssr' => 'vite build --ssr']])]));
        $viaFile = servicesOf(inspectLaravel([...$inertia, 'package.json' => '{}', 'resources/js/ssr.tsx' => '']));

        foreach ([$viaScript, $viaFile] as $services) {
            expect($services['ssr'])->toMatchArray(['default_command' => 'php artisan inertia:start-ssr', 'needs_node' => true, 'supported' => true])
                ->and($services['ssr']['env_note'])->toContain('INERTIA_SSR_URL');
        }

        // Inertia without any SSR sign is just a front end.
        expect(servicesOf(inspectLaravel([...$inertia, 'package.json' => json_encode(['scripts' => ['build' => 'vite build']])])))->not->toHaveKey('ssr');
        expect(servicesOf(inspectLaravel(['package.json' => json_encode(['scripts' => ['build:ssr' => 'x']])])))->not->toHaveKey('ssr');
    });

    it('finds Reverb but marks it as not supported yet', function () {
        $services = servicesOf(inspectLaravel(['composer.lock' => json_encode(['packages' => [['name' => 'laravel/reverb', 'version' => 'v1.0.0']]])]));

        expect($services['reverb'])->toMatchArray(['supported' => false, 'default_enabled' => false])->and($services['reverb']['note'])->toContain('public address');
    });

    it('turns on nothing but the scheduler by default', function () {
        $detected = inspectLaravel(['composer.lock' => json_encode(['packages' => [
            ['name' => 'laravel/horizon', 'version' => '1'], ['name' => 'laravel/pulse', 'version' => '1'], ['name' => 'laravel/reverb', 'version' => '1'],
        ]])]);

        expect(collect($detected['services'])->where('default_enabled', true)->pluck('kind')->all())->toBe(['scheduler']);
    });
});

it('warns when Octane is installed, because the app is served with nginx and PHP-FPM', function () {
    $require = json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^12.0', 'laravel/octane' => '^2.0']]);

    expect(inspectLaravel(['composer.json' => $require])['warnings'])->toBe(['laravel/octane is installed, but this app is built with nginx and PHP-FPM. To run Octane, add a Dockerfile to the repository.'])
        ->and(inspectLaravel([])['warnings'])->toBe([]);
});

it('reads the database and queue defaults and connections from the config files', function () {
    $detected = inspectLaravel([
        'config/database.php' => "<?php return ['default' => env('DB_CONNECTION', 'mysql'), 'connections' => ['sqlite' => [], 'mysql' => [], 'pgsql' => []]];",
        'config/queue.php' => "<?php return ['default' => env('QUEUE_CONNECTION', 'redis')];",
    ]);

    expect($detected['db_default'])->toBe('mysql')
        ->and($detected['db_connections'])->toBe(['sqlite', 'mysql', 'pgsql'])
        ->and($detected['queue_default'])->toBe('redis');
});

it('suggests a volume for storage', function () {
    expect(inspectLaravel([])['suggested_volumes'])->toBe([[
        'path' => '/app/storage',
        'why' => 'Uploads, sessions, cache files and logs are written here. Without a volume they are lost at every deployment.',
    ]]);
});

it('lists variable names from .env.example and never a value', function () {
    $detected = inspectLaravel(['.env.example' => "APP_NAME=Laravel\n# MAIL_HOST=smtp\nexport DB_PASSWORD=hunter2\n\nREDIS_PASSWORD=null\nlowercase=x\nAPP_NAME=Again\n"]);

    expect($detected['env_hints'])->toBe(['APP_NAME', 'DB_PASSWORD', 'REDIS_PASSWORD'])
        ->and(json_encode($detected))->not->toContain('hunter2')->not->toContain('smtp');
});

it('never executes the project', function () {
    $marker = sys_get_temp_dir().'/cp-executed-'.bin2hex(random_bytes(4));

    inspectLaravel([
        'artisan' => '<?php file_put_contents('.var_export($marker, true).', "x");',
        'bootstrap/app.php' => '<?php file_put_contents('.var_export($marker, true).', "x"); health: \'/up\'',
        'config/database.php' => '<?php file_put_contents('.var_export($marker, true).', "x");',
    ]);

    expect(file_exists($marker))->toBeFalse();
});

it('does not read a file that is unreasonably large or a link', function () {
    $dir = sourceTree(['artisan' => '<?php']);
    file_put_contents($dir.'/composer.json', '{"require":{"php":"^8.3","laravel/framework":"^12.0"}}');
    file_put_contents($dir.'/package.json', str_repeat(' ', 9_000_000).'{"scripts":{"build":"x"}}');
    file_put_contents($dir.'/outside.json', '{"scripts":{"build":"x"}}');
    symlink($dir.'/outside.json', $dir.'/composer.lock');

    $detected = (new LaravelInspector)->inspect($dir);

    expect($detected['has_package_json'])->toBeFalse();
});
