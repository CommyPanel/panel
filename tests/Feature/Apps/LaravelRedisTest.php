<?php

use App\Databases\AppLinks;
use App\Deploy\LaravelEnvTemplate;
use App\Deploy\SetupAppRedis;
use App\Deploy\Stacks\Laravel\LaravelInspector;
use App\Enums\SetupStep;
use App\Enums\Stack;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../../Unit/Deploy/SourceHelpers.php';

/**
 * The Redis choice of the Laravel wizard: none, one of the person's own Redis apps, or a new Redis made
 * for the app. The link gives the variables Laravel's redis settings read.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config(['commypanel.data_path' => storage_path('framework/testing/lredis-'.bin2hex(random_bytes(4)))]);
    Queue::fake();

    $this->shop = App::factory()->create([
        'name' => 'Shop', 'slug' => 'shop', 'setup_step' => SetupStep::Database, 'stack' => null, 'stack_hint' => Stack::Laravel,
        'source_verified_at' => now(), 'detected' => ['stack' => 'laravel', 'redis' => ['suggested' => true, 'why' => 'laravel/horizon']],
    ]);
    $this->choose = fn (array $body) => $this->putJson('/apps/shop/setup/redis', $body);
    $this->redisApps = fn () => App::query()->where('template', 'redis')->orderBy('id')->get();
});

afterEach(fn () => File::deleteDirectory((string) config('commypanel.data_path')));

/** A finished Redis app of the person's own. */
function finishedRedis(string $name = 'Cache'): App
{
    test()->post('/apps', ['name' => $name, 'source' => 'template', 'template' => 'redis', 'settings' => []])->assertRedirect();
    $app = App::query()->where('template', 'redis')->latest('id')->firstOrFail();
    $app->forceFill(['setup_step' => null])->save();

    return $app;
}

describe('a new Redis for the app', function () {
    it('makes a Redis app, links it and starts its first release', function () {
        ($this->choose)(['mode' => 'new'])->assertOk()->assertJsonPath('choice.mode', 'new');

        $redis = ($this->redisApps)()->sole();

        expect($redis->name)->toBe('Shop Redis')
            ->and($redis->setup_step)->toBeNull()
            ->and($redis->deployments()->count())->toBe(1)
            ->and($this->shop->fresh()->links()->pluck('env_prefix')->all())->toBe(['REDIS'])
            ->and($this->shop->fresh()->stack_options['redis'])->toBe(['mode' => 'new', 'app' => $redis->slug])
            ->and(AuditLog::query()->whereIn('action', ['app.setup_started', 'app.created', 'app.redis_chosen'])->count())->toBe(3);
        Queue::assertPushed(RunDeployment::class, 1);
    });

    it('gives the app the variables Laravel reads', function () {
        ($this->choose)(['mode' => 'new']);
        $redis = ($this->redisApps)()->sole();
        $password = $redis->environment()['REDIS_PASSWORD'];

        $env = app(AppLinks::class)->environment($this->shop->fresh());

        expect($env)->toMatchArray([
            'REDIS_URL' => "redis://:{$password}@{$redis->slug}:6379",
            'REDIS_HOST' => $redis->slug,
            'REDIS_PORT' => '6379',
            'REDIS_PASSWORD' => $password,
        ]);
    });

    it('is idempotent: coming back reuses the Redis app', function () {
        ($this->choose)(['mode' => 'new']);
        ($this->choose)(['mode' => 'new'])->assertOk();

        expect(($this->redisApps)())->toHaveCount(1)
            ->and($this->shop->fresh()->links()->count())->toBe(1);
        Queue::assertPushed(RunDeployment::class, 1);
    });
});

describe('an existing Redis', function () {
    it('links one of the person\'s Redis apps, and nothing else', function () {
        $cache = finishedRedis();
        $other = App::factory()->create(['slug' => 'blog', 'template' => null]);

        ($this->choose)(['mode' => 'existing', 'app' => $cache->slug])->assertOk()->assertJsonPath('choice.app', $cache->slug);
        expect($this->shop->fresh()->links()->first()->target_app_id)->toBe($cache->id);

        ($this->choose)(['mode' => 'existing', 'app' => $other->slug])->assertJsonValidationErrors('app');
        ($this->choose)(['mode' => 'existing', 'app' => 'nope'])->assertJsonValidationErrors('app');
        ($this->choose)(['mode' => 'existing'])->assertJsonValidationErrors('app');
        Queue::assertNothingPushed();
    });

    it('does not offer a Redis app that is still being set up', function () {
        $this->post('/apps', ['name' => 'Draft Redis', 'source' => 'template', 'template' => 'redis', 'settings' => []]);

        ($this->choose)(['mode' => 'existing', 'app' => 'draft-redis'])->assertJsonValidationErrors('app');
        expect(app(SetupAppRedis::class)->existing())->toBe([]);
    });
});

describe('changing the choice', function () {
    it('drops the old link, keeps the Redis app that was made, and can go back to none', function () {
        ($this->choose)(['mode' => 'new']);
        $made = ($this->redisApps)()->sole();
        $cache = finishedRedis();

        ($this->choose)(['mode' => 'existing', 'app' => $cache->slug]);
        expect($this->shop->fresh()->links()->pluck('target_app_id')->all())->toBe([$cache->id])
            ->and(App::query()->whereKey($made->id)->exists())->toBeTrue();

        ($this->choose)(['mode' => 'none'])->assertOk()->assertJsonPath('choice.mode', 'none');
        expect($this->shop->fresh()->links()->count())->toBe(0)
            ->and(app(SetupAppRedis::class)->linked($this->shop->fresh()))->toBeFalse();
    });

    it('keeps what other steps stored beside it', function () {
        $this->shop->forceFill(['stack_options' => ['php' => '8.4', 'database_step' => ['mode' => 'none']]])->save();

        ($this->choose)(['mode' => 'none']);

        expect($this->shop->fresh()->stack_options)->toMatchArray(['php' => '8.4', 'database_step' => ['mode' => 'none'], 'redis' => ['mode' => 'none']]);
    });
});

describe('who may choose', function () {
    it('needs the permission to link apps, and to create apps for a new Redis', function () {
        $user = User::factory()->regular()->create();
        $user->givePermissionTo(Permission::findOrCreate('apps.create'));
        $this->actingAs($user);

        ($this->choose)(['mode' => 'new'])->assertForbidden();
        ($this->choose)(['mode' => 'none'])->assertOk();

        $user->givePermissionTo(Permission::findOrCreate('apps.links'));
        ($this->choose)(['mode' => 'new'])->assertOk();
    });

    it('is only for a draft past the source step', function () {
        $this->shop->update(['setup_step' => SetupStep::Source]);
        ($this->choose)(['mode' => 'none'])->assertNotFound();

        $this->shop->update(['setup_step' => null]);
        ($this->choose)(['mode' => 'none'])->assertNotFound();
    });

    it('refuses an unknown mode', function () {
        ($this->choose)(['mode' => 'cluster'])->assertJsonValidationErrors('mode');
    });
});

describe('the wizard page and the variables', function () {
    it('shows the suggestion, the choice and the Redis apps to pick from', function () {
        $cache = finishedRedis();

        $this->get('/apps/shop/setup')->assertInertia(fn (Assert $page) => $page
            ->where('laravel.redis.suggested', true)
            ->where('laravel.redis.why', 'laravel/horizon')
            ->where('laravel.redis.choice.mode', null)
            ->where('laravel.redis.existing.0.slug', $cache->slug)
            ->where('laravel.redis.can.link', true)
            ->missing('laravel.stack_options.redis'));
    });

    it('starts the .env text with Redis for the cache and sessions, and for the queue when a worker runs', function () {
        ($this->choose)(['mode' => 'new']);
        $this->shop->update(['setup_step' => SetupStep::Domain]);
        $this->shop->processes()->create(['name' => 'queue', 'kind' => 'queue', 'enabled' => true, 'sort' => 1]);

        $this->post('/apps/shop/setup/domain/continue')->assertRedirect();
        $env = $this->shop->fresh()->environment();

        expect([$env['CACHE_STORE'], $env['SESSION_DRIVER'], $env['QUEUE_CONNECTION']])->toBe(['redis', 'redis', 'redis']);
    });

    it('keeps the queue off Redis when nothing runs jobs, and files when no Redis is linked', function () {
        $template = new LaravelEnvTemplate;

        $linkedNoWorker = $template->generate($this->shop, null, false, true);
        $plain = $template->generate($this->shop, null, true, false);

        expect($linkedNoWorker)->toContain('CACHE_STORE=redis')->toContain('QUEUE_CONNECTION=sync')
            ->and($plain)->toContain('CACHE_STORE=file')->toContain('QUEUE_CONNECTION=database');
    });
});

describe('what the code shows', function () {
    $inspect = fn (array $files): array => (new LaravelInspector)->inspect(sourceTree([
        'artisan' => '<?php',
        'composer.json' => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^12.0']]),
        ...$files,
    ]))['redis'];

    it('suggests Redis for Horizon, predis, ext-redis and a redis driver in .env.example', function () use ($inspect) {
        expect($inspect(['composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0', 'laravel/horizon' => '^5.0']])]))->toBe(['suggested' => true, 'why' => 'laravel/horizon'])
            ->and($inspect(['composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0', 'predis/predis' => '^2.0']])])['why'])->toBe('predis/predis')
            ->and($inspect(['composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0', 'ext-redis' => '*']])])['why'])->toBe('ext-redis')
            ->and($inspect(['.env.example' => "APP_NAME=Laravel\nQUEUE_CONNECTION=redis\n"]))->toBe(['suggested' => true, 'why' => 'QUEUE_CONNECTION=redis'])
            ->and($inspect(['.env.example' => "SESSION_DRIVER=\"redis\"\n"])['why'])->toBe('SESSION_DRIVER=redis');
    });

    it('suggests nothing for a plain app', function () use ($inspect) {
        expect($inspect(['.env.example' => "QUEUE_CONNECTION=database\nCACHE_STORE=file\n"]))->toBe(['suggested' => false, 'why' => null])
            ->and($inspect([]))->toBe(['suggested' => false, 'why' => null]);
    });
});
