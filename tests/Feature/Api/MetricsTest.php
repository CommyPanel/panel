<?php

use App\Api\TokenAccess;
use App\Auth\SyncRolesAndPermissions;
use App\Databases\DatabaseServers;
use App\Enums\DatabaseEngine;
use App\Enums\DeploymentStatus;
use App\Host\Data\ContainerSpec;
use App\Host\Data\ResourceUsage;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Http\Middleware\LimitApiFailures;
use App\Metrics\PrometheusText;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\Certificate;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * What a Prometheus gets from /api/metrics: the usual API protection, only numbers and names, and for
 * a token limited to some apps only those apps.
 */
beforeEach(function () {
    app(SyncRolesAndPermissions::class)();
    $this->user = User::factory()->create();
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->liveApp = function (string $slug, int $port): App {
        $app = App::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $deployment = Deployment::factory()->create(['app_id' => $app->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'container_name' => "{$slug}-1"]);
        $app->forceFill(['active_deployment_id' => $deployment->id])->save();
        $this->host->runContainer(new ContainerSpec("{$slug}-1", "commypanel/{$slug}:1", $port, 8080, memoryLimitMb: 256));

        return $app;
    };

    $this->token = fn (array $abilities = [TokenAccess::METRICS], array $apps = [], ?User $owner = null): string => app(TokenAccess::class)
        ->create($owner ?? $this->user, 'prometheus', $abilities, $apps, 30)->plainTextToken;
});

function scrape(string $token): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->get('/api/metrics');
}

it('is behind a token, the failure counter, the rate limit, its own ability and a permission', function () {
    $route = Route::getRoutes()->getByName('api.metrics');
    $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));

    expect($middleware->all())->toContain('auth:sanctum', LimitApiFailures::class, 'throttle:api', 'abilities:metrics:read', 'can:server.view');
});

it('refuses a call without a token or with a made-up one, as a plain JSON error', function () {
    $this->get('/api/metrics')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken('cpt_not-a-token')->get('/api/metrics')->assertUnauthorized();
});

it('refuses a token without the metrics ability, and a token whose owner may not see the server', function () {
    scrape(($this->token)([TokenAccess::READ]))->assertForbidden();

    $owner = User::factory()->regular()->create();
    $token = $owner->createToken('x', [TokenAccess::METRICS])->plainTextToken;

    scrape($token)->assertForbidden();
});

it('answers in the Prometheus text format, with the host, each app and each database server', function () {
    ($this->liveApp)('shop', 20001);
    app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17');

    $response = scrape(($this->token)())->assertOk();
    $body = $response->getContent();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain; version=0.0.4')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($body)->toContain('# TYPE commypanel_host_cpu_usage_ratio gauge')
        ->toContain('commypanel_agent_up 1')
        ->toContain('commypanel_host_memory_total_bytes 4294967296')
        ->toContain('commypanel_app_up{app="shop"} 1')
        ->toContain('commypanel_app_status_info{app="shop",status="running"} 1')
        ->toContain('commypanel_container_memory_limit_bytes{container="shop-1",app="shop",kind="app"} 268435456')
        ->toContain('commypanel_database_server_up{server="main",engine="postgres",version="17"} 1');

    // Every line is a comment or "name{labels} number".
    foreach (explode("\n", trim($body)) as $line) {
        expect($line)->toMatch('/^(# (HELP|TYPE) \w+ .+|\w+(\{[^}]*\})? -?\d+(\.\d+)?)$/');
    }
});

it('shows a database server container with its own kind, and an app that is not running as 0', function () {
    app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17');
    App::factory()->create(['slug' => 'idle']);

    $body = scrape(($this->token)())->getContent();

    expect($body)->toContain('kind="database"')
        ->toContain('commypanel_app_up{app="idle"} 0')
        ->toContain('commypanel_app_status_info{app="idle",status="new"} 1');
});

it('gives the end of an app\'s certificate as a Unix time, only while one is usable', function () {
    $app = ($this->liveApp)('shop', 20001);
    $expires = now()->addDays(40)->startOfSecond();
    Certificate::query()->create(['app_id' => $app->id, 'status' => 'active', 'domains' => ['shop.example.com'], 'issued_at' => now(), 'expires_at' => $expires]);

    expect(scrape(($this->token)())->getContent())->toContain('commypanel_app_certificate_expiry_timestamp_seconds{app="shop"} '.$expires->getTimestamp());
});

it('gives a token that is limited to some apps only those apps, and nothing about the server', function () {
    ($this->liveApp)('shop', 20001);
    ($this->liveApp)('blog', 20002);
    app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17');

    $body = scrape(($this->token)([TokenAccess::METRICS], ['shop']))->assertOk()->getContent();

    expect($body)->toContain('app="shop"')
        ->not->toContain('blog')
        ->not->toContain('commypanel_host_')
        ->not->toContain('commypanel_database_server_up')
        ->not->toContain('commypanel_agent_up')
        ->not->toContain('kind="database"');
});

it('says the agent is down, and still answers, when the host cannot be read', function () {
    ($this->liveApp)('shop', 20001);
    app()->instance(HostDriver::class, new class extends FakeHostDriver
    {
        public function resourceUsage(bool $history = false): ResourceUsage
        {
            throw new HostOperationFailed('Agent error: unavailable');
        }
    });
    Cache::forget('metrics.usage');

    $body = scrape(($this->token)())->assertOk()->getContent();

    expect($body)->toContain('commypanel_agent_up 0')->toContain('commypanel_app_up{app="shop"} 1')->not->toContain('commypanel_host_');
});

it('never shows variables, logs, addresses or secrets', function () {
    $app = ($this->liveApp)('shop', 20001);
    AppEnvVar::query()->create(['app_id' => $app->id, 'key' => 'STRIPE_KEY', 'value' => 'sk_live_supersecret']);
    $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17');

    $body = scrape(($this->token)())->getContent();

    expect($body)->not->toContain('sk_live_supersecret')->not->toContain('STRIPE_KEY')->not->toContain((string) $server->fresh()->root_password);
});

it('escapes what could break out of a label', function () {
    $text = new PrometheusText;
    $text->gauge('commypanel_test', "Line\nbreak", 1.5, ['app' => "a\"b\\c\nd"]);

    expect($text->render())->toBe("# HELP commypanel_test Line\\nbreak\n# TYPE commypanel_test gauge\ncommypanel_test{app=\"a\\\"b\\\\c\\nd\"} 1.5\n");
});

it('is described in the token page and the audit of token creation like any other ability', function () {
    expect(array_keys(TokenAccess::abilities()))->toContain('metrics:read')
        ->and(TokenAccess::abilities()['metrics:read']['permission'])->toBe('server.view');
});
