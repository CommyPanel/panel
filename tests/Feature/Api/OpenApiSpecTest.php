<?php

use App\Api\TokenAccess;
use App\Auth\SyncRolesAndPermissions;
use App\Enums\AppStatus;
use App\Enums\DeploymentStage;
use App\Enums\DeploymentStatus;
use App\Enums\Stack;
use App\Host\Data\ContainerSpec;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\OpenApiSpec;

/**
 * docs/openapi.yaml is the contract the clients are written against. These tests keep it honest: it
 * lists exactly the routes there are, with the abilities they need, and what the API answers fits it.
 */
beforeEach(function () {
    $this->spec = new OpenApiSpec;
});

/** The API's routes as "METHOD /path" => ability (null when none). */
function apiRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $path = preg_replace('/\{(\w+)(:\w+)?\}/', '{$1}', substr($route->uri(), strlen('api/v1')));
        $path = str_replace('{app}', '{slug}', $path);
        $ability = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'abilities:'));

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[$method.' '.$path] = $ability === null ? null : substr($ability, strlen('abilities:'));
        }
    }

    ksort($routes);

    return $routes;
}

it('describes exactly the routes the API has, with the ability each one needs', function () {
    $documented = collect($this->spec->operations())->map(fn ($op) => $op['x-ability'] ?? null)->sortKeys()->all();

    expect($documented)->toBe(apiRoutes());
});

it('is a well-formed document with unique operation ids and the standard errors', function () {
    $ids = [];

    foreach ($this->spec->operations() as $key => $operation) {
        $ids[] = $operation['operationId'] ?? throw new RuntimeException("{$key} has no operationId");

        $statuses = array_map('strval', array_keys($operation['responses']));
        expect($statuses)->toContain('401', '429');

        if (isset($operation['x-ability'])) {
            expect($statuses)->toContain('403');
        }
    }

    expect($ids)->toBe(array_values(array_unique($ids)))
        ->and($this->spec->doc['openapi'])->toStartWith('3.0')
        ->and($this->spec->doc['info']['version'])->toBe('1');
});

it('lists the same abilities, statuses and stacks as the code', function () {
    $schemas = $this->spec->doc['components']['schemas'];
    $values = fn (string $enum) => collect($enum::cases())->map->value->sort()->values()->all();
    $enumOf = fn (array $property) => collect($property['enum'])->sort()->values()->all();

    expect($enumOf($schemas['Token']['properties']['abilities']['items']))->toBe(collect(array_keys(TokenAccess::abilities()))->sort()->values()->all())
        ->and($enumOf($schemas['App']['properties']['status']))->toBe($values(AppStatus::class))
        ->and($enumOf($schemas['App']['properties']['stack']))->toBe($values(Stack::class))
        ->and($enumOf($schemas['Deployment']['properties']['status']))->toBe($values(DeploymentStatus::class))
        ->and($enumOf($schemas['Deployment']['properties']['stage']))->toBe($values(DeploymentStage::class));
});

it('never describes a secret in an answer: no values, passwords or keys', function () {
    $secret = fn (string $name) => (bool) preg_match('/password|secret|private|credential|api_?key|^value$|^values$/i', $name);
    $walk = function (array $node, string $path) use (&$walk, $secret) {
        foreach ($node['properties'] ?? [] as $name => $child) {
            expect($secret($name))->toBeFalse("{$path}.{$name} looks like a secret");
            $walk($child, "{$path}.{$name}");
        }
        foreach (['items'] as $key) {
            if (isset($node[$key])) {
                $walk($node[$key], "{$path}[]");
            }
        }
    };

    foreach ($this->spec->doc['components']['schemas'] as $name => $schema) {
        $walk($schema, $name);
    }
});

describe('what the API answers fits the spec', function () {
    beforeEach(function () {
        // As in production: with debug on, errors carry a trace.
        config(['app.debug' => false]);
        Queue::fake();
        app(SyncRolesAndPermissions::class)();
        $this->user = User::factory()->create();
        $host = app(HostDriver::class);

        $this->app1 = App::factory()->create(['slug' => 'shop', 'name' => 'Shop']);
        $deployment = Deployment::factory()->create([
            'app_id' => $this->app1->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'host_port' => 20001,
            'container_name' => 'shop-1', 'image_tag' => 'commypanel/shop:1', 'image_available' => true,
        ]);
        $this->app1->update(['active_deployment_id' => $deployment->id]);
        $host->runContainer(new ContainerSpec('shop-1', 'commypanel/shop:1', 20001, 8080));
        AppEnvVar::query()->create(['app_id' => $this->app1->id, 'key' => 'EXISTING', 'value' => 'x']);

        $token = app(TokenAccess::class)->create($this->user, 'ci', array_keys(TokenAccess::abilities()), [], 30)->plainTextToken;
        $this->call = function (string $method, string $uri, array $body = []) use ($token) {
            app('auth')->forgetGuards();

            return $this->withToken($token)->withHeaders(['Accept' => 'application/json'])->json($method, $uri, $body);
        };
        $this->expectFit = function (string $key, $response) {
            $schema = $this->spec->responseSchema($key, $response->getStatusCode());
            expect($schema)->not->toBeNull("{$key} does not document status {$response->getStatusCode()}");
            expect($this->spec->check($response->json(), $schema))->toBe([]);
        };
    });

    it('for every call that succeeds', function (string $key, string $method, string $uri, array $body, string $first = '') {
        if ($first !== '') {
            ($this->call)('POST', $first)->assertOk(); // a state the call needs (start needs a stopped app)
        }

        $response = ($this->call)($method, $uri, $body);
        expect($response->status())->toBeLessThan(300);
        ($this->expectFit)($key, $response);
    })->with([
        'token' => ['GET /token', 'GET', '/api/v1/token', []],
        'list apps' => ['GET /apps', 'GET', '/api/v1/apps', []],
        'one app' => ['GET /apps/{slug}', 'GET', '/api/v1/apps/shop', []],
        'list deployments' => ['GET /apps/{slug}/deployments', 'GET', '/api/v1/apps/shop/deployments', []],
        'one deployment' => ['GET /apps/{slug}/deployments/{number}', 'GET', '/api/v1/apps/shop/deployments/1?log=1', []],
        'logs' => ['GET /apps/{slug}/logs', 'GET', '/api/v1/apps/shop/logs', []],
        'env names' => ['GET /apps/{slug}/env', 'GET', '/api/v1/apps/shop/env', []],
        'set a variable' => ['PUT /apps/{slug}/env/{key}', 'PUT', '/api/v1/apps/shop/env/NEW_ONE', ['value' => 'v']],
        'replace a variable' => ['PUT /apps/{slug}/env/{key}', 'PUT', '/api/v1/apps/shop/env/EXISTING', ['value' => 'y']],
        'remove a variable' => ['DELETE /apps/{slug}/env/{key}', 'DELETE', '/api/v1/apps/shop/env/EXISTING', []],
        'restart' => ['POST /apps/{slug}/restart', 'POST', '/api/v1/apps/shop/restart', []],
        'stop' => ['POST /apps/{slug}/stop', 'POST', '/api/v1/apps/shop/stop', []],
        'start (after a stop)' => ['POST /apps/{slug}/start', 'POST', '/api/v1/apps/shop/start', [], '/api/v1/apps/shop/stop'],
        'start a deployment' => ['POST /apps/{slug}/deployments', 'POST', '/api/v1/apps/shop/deployments', []],
    ]);

    it('for the errors', function () {
        // A deployment is queued first, so the next calls conflict.
        ($this->call)('POST', '/api/v1/apps/shop/deployments')->assertStatus(202);

        ($this->expectFit)('POST /apps/{slug}/deployments', ($this->call)('POST', '/api/v1/apps/shop/deployments'));
        ($this->expectFit)('POST /apps/{slug}/apply', ($this->call)('POST', '/api/v1/apps/shop/apply'));
        ($this->expectFit)('POST /apps/{slug}/restart', ($this->call)('POST', '/api/v1/apps/shop/restart'));
        ($this->expectFit)('GET /apps/{slug}', ($this->call)('GET', '/api/v1/apps/nope'));
        ($this->expectFit)('PUT /apps/{slug}/env/{key}', ($this->call)('PUT', '/api/v1/apps/shop/env/PORT', ['value' => '1']));
    });

    it('for a request without a token', function () {
        $response = $this->getJson('/api/v1/apps');

        expect($this->spec->check($response->json(), $this->spec->responseSchema('GET /apps', 401)))->toBe([]);
    });

    it('for a call the ability does not cover', function () {
        $read = app(TokenAccess::class)->create($this->user, 'ro', [TokenAccess::READ], [], 30)->plainTextToken;
        app('auth')->forgetGuards();
        $response = $this->withToken($read)->withHeaders(['Accept' => 'application/json'])->postJson('/api/v1/apps/shop/stop');

        expect($response->status())->toBe(403)
            ->and($this->spec->check($response->json(), $this->spec->responseSchema('POST /apps/{slug}/stop', 403)))->toBe([]);
    });
});

it('really catches an answer that does not fit (the checker is not asleep)', function () {
    $schema = $this->spec->doc['components']['schemas']['EnvChange'];

    expect($this->spec->check(['key' => 'A', 'pending_changes' => true], $schema))->toBe([])
        ->and($this->spec->check(['key' => 'A'], $schema))->not->toBe([])
        ->and($this->spec->check(['key' => 'A', 'pending_changes' => true, 'value' => 'leak'], $schema))->not->toBe([])
        ->and($this->spec->check(['key' => 1, 'pending_changes' => true], $schema))->not->toBe([]);
});
