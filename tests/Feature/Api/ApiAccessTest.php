<?php

use App\Api\TokenAccess;
use App\Auth\SyncRolesAndPermissions;
use App\Http\Middleware\LimitApiFailures;
use App\Models\App;
use App\Models\User;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * The rule for the whole API: without a valid token no call works, and a token only does what its abilities
 * and its owner allow. These tests walk every route there is (also the ones added later), so a new call
 * cannot be added without that protection.
 */

/** @return list<RouteDefinition> */
function apiCalls(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/v1/') && ! $route->isFallback,
    ));
}

/** The address of a route with its placeholders filled in. */
function addressOf(RouteDefinition $route): string
{
    return '/'.preg_replace(['/\{app(:\w+)?\}/', '/\{number\}/', '/\{key\}/'], ['shop', '1', 'SOME_KEY'], $route->uri());
}

function method(RouteDefinition $route): string
{
    return array_values(array_diff($route->methods(), ['HEAD']))[0];
}

beforeEach(function () {
    app(SyncRolesAndPermissions::class)();
    $this->user = User::factory()->create();
    App::factory()->create(['slug' => 'shop']);
    // Every refused call counts against the address; start each call fresh so all of them are really tried.
    $this->fresh = fn () => RateLimiter::clear('api-failures:127.0.0.1');
});

it('has routes to walk (so the other tests mean something)', function () {
    expect(count(apiCalls()))->toBeGreaterThanOrEqual(14);
});

it('puts every API route behind a token, the failure counter and the rate limit', function () {
    foreach (apiCalls() as $route) {
        $middleware = $route->gatherMiddleware();

        foreach (['auth:sanctum', LimitApiFailures::class, 'throttle:api'] as $required) {
            expect(in_array($required, $middleware, true))->toBeTrue("{$route->uri()} lacks {$required}");
        }
    }
});

it('gives every API route except the token check an ability and a permission', function () {
    foreach (apiCalls() as $route) {
        $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));

        if ($route->getName() === 'api.token.show') {
            continue;
        }

        expect($middleware->contains(fn ($m) => str_starts_with($m, 'abilities:')))->toBeTrue("{$route->uri()} has no ability")
            ->and($middleware->contains(fn ($m) => str_starts_with($m, 'can:')))->toBeTrue("{$route->uri()} has no permission check");
    }
});

it('refuses every call without a token, with a made-up one, a revoked one and an expired one', function () {
    $revoked = app(TokenAccess::class)->create($this->user, 'old', array_keys(TokenAccess::abilities()), [], 30);
    $revoked->accessToken->delete();
    $expired = app(TokenAccess::class)->create($this->user, 'late', array_keys(TokenAccess::abilities()), [], 1)->plainTextToken;
    $this->travel(2)->days();
    $cases = ['none' => null, 'made up' => 'cpt_not-a-token', 'garbled' => '1|', 'revoked' => $revoked->plainTextToken, 'expired' => $expired];

    foreach (apiCalls() as $route) {
        foreach ($cases as $name => $token) {
            ($this->fresh)();
            app('auth')->forgetGuards();
            $request = $token === null ? $this : $this->withToken($token);
            $response = $request->withHeaders(['Accept' => 'application/json'])->json(method($route), addressOf($route), ['value' => 'x']);

            expect($response->status())->toBe(401, "{$route->uri()} with {$name} token answered {$response->status()}");
        }
    }
});

it('refuses every call a token has no ability for, except the token check', function () {
    $nothingUseful = app(TokenAccess::class)->create($this->user, 'none', [], [], 30)->plainTextToken;

    foreach (apiCalls() as $route) {
        app('auth')->forgetGuards();
        $response = $this->withToken($nothingUseful)->withHeaders(['Accept' => 'application/json'])->json(method($route), addressOf($route), ['value' => 'x']);

        expect($response->status())->toBe($route->getName() === 'api.token.show' ? 200 : 403, "{$route->uri()} answered {$response->status()}");
    }
});

it('refuses every call whose ability the token has but whose permission its owner does not', function () {
    $owner = User::factory()->regular()->create(); // no role, so no permission at all
    $token = $owner->createToken('x', array_keys(TokenAccess::abilities()))->plainTextToken;

    foreach (apiCalls() as $route) {
        if ($route->getName() === 'api.token.show') {
            continue;
        }

        app('auth')->forgetGuards();
        $response = $this->withToken($token)->withHeaders(['Accept' => 'application/json'])->json(method($route), addressOf($route), ['value' => 'x']);

        expect($response->status())->toBe(403, "{$route->uri()} answered {$response->status()}");
    }
});

it('stops working the moment its owner is deleted', function () {
    $owner = User::factory()->regular()->create();
    $token = app(TokenAccess::class)->create($owner, 'ci', [], [], 30)->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/token')->assertOk();

    $owner->delete();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/token')->assertUnauthorized();
});
