<?php

use App\Api\TokenAccess;
use App\Auth\SyncRolesAndPermissions;
use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Enums\SetupStep;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Queue::fake();
    app(SyncRolesAndPermissions::class)();
    $this->user = User::factory()->create();
    $this->shop = App::factory()->create(['slug' => 'shop', 'name' => 'Shop']);
    $this->blog = App::factory()->create(['slug' => 'blog', 'name' => 'Blog']);

    // A token as a person would make it, so the abilities and the app limits are the real ones.
    $this->token = function (array $abilities = [TokenAccess::READ, TokenAccess::DEPLOY], array $apps = [], ?User $user = null) {
        return app(TokenAccess::class)->create($user ?? $this->user, 'ci', $abilities, $apps, 30)->plainTextToken;
    };
    // One test makes several calls with different tokens, and a guard remembers the user it found.
    $this->api = function (string $token) {
        app('auth')->forgetGuards();

        return $this->withToken($token)->withHeaders(['Accept' => 'application/json']);
    };
});

describe('who gets in', function () {
    it('wants a token on every call', function () {
        $this->getJson('/api/v1/apps')->assertUnauthorized();
        $this->postJson('/api/v1/apps/shop/deployments')->assertUnauthorized();
        $this->withToken('cpt_nonsense')->getJson('/api/v1/apps')->assertUnauthorized();
    });

    it('never takes a browser session instead of a token', function () {
        $this->actingAs($this->user)->getJson('/api/v1/apps')->assertUnauthorized();
        $this->actingAs($this->user)->postJson('/api/v1/apps/shop/deployments')->assertUnauthorized();
    });

    it('makes tokens that are recognisable and are stored only as a hash', function () {
        $plain = ($this->token)();

        expect($plain)->toMatch('/^\d+\|cpt_/')
            ->and(DB::table('personal_access_tokens')->where('token', $plain)->exists())->toBeFalse()
            ->and(DB::table('personal_access_tokens')->count())->toBe(1);
    });

    it('refuses an expired token', function () {
        $plain = ($this->token)();
        $this->travel(31)->days();

        ($this->api)($plain)->getJson('/api/v1/apps')->assertUnauthorized();
    });

    it('refuses a revoked token', function () {
        $plain = ($this->token)();
        $this->user->tokens()->delete();

        ($this->api)($plain)->getJson('/api/v1/apps')->assertUnauthorized();
    });

    it('counts wrong tokens first, so guessing is slow, and never counts a good one', function () {
        $good = ($this->token)();

        for ($i = 0; $i < 10; $i++) {
            $this->withToken('cpt_guess'.$i)->getJson('/api/v1/apps')->assertUnauthorized();
        }

        // The eleventh is refused before its token is even looked at, a good token too.
        $this->withToken('cpt_one_more')->getJson('/api/v1/apps')->assertStatus(429)->assertHeader('Retry-After');
        ($this->api)($good)->getJson('/api/v1/apps')->assertStatus(429);

        // The minute passes.
        $this->travel(61)->seconds();
        ($this->api)($good)->getJson('/api/v1/apps')->assertOk();
    });

    it('does not count good calls against anyone', function () {
        $good = ($this->token)();

        for ($i = 0; $i < 30; $i++) {
            ($this->api)($good)->getJson('/api/v1/apps')->assertOk();
        }
    });

    it('limits a token to 60 calls a minute', function () {
        $good = ($this->token)();

        for ($i = 0; $i < 60; $i++) {
            ($this->api)($good)->getJson('/api/v1/apps')->assertOk();
        }

        ($this->api)($good)->getJson('/api/v1/apps')->assertStatus(429);
    });

    it('does not offer itself to other websites', function () {
        $response = ($this->api)(($this->token)())->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/apps');

        expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
    });
});

describe('abilities', function () {
    it('lets a read token read but not deploy, and a deploy token deploy but not read', function () {
        $read = ($this->token)([TokenAccess::READ]);
        $deploy = ($this->token)([TokenAccess::DEPLOY]);

        ($this->api)($read)->getJson('/api/v1/apps')->assertOk();
        ($this->api)($read)->postJson('/api/v1/apps/shop/deployments')->assertForbidden();
        ($this->api)($deploy)->getJson('/api/v1/apps')->assertForbidden();
        ($this->api)($deploy)->postJson('/api/v1/apps/shop/deployments')->assertStatus(202);
    });

    it('gives a token only the abilities its owner has', function () {
        $viewer = User::factory()->create(['is_super' => false]);
        $viewer->assignRole(Role::findOrCreate('viewer-only'));
        $viewer->givePermissionTo('apps.view');

        $token = app(TokenAccess::class)->create($viewer, 'ci', [TokenAccess::READ, TokenAccess::DEPLOY], [], 30);

        expect($token->accessToken->abilities)->toBe([TokenAccess::READ])
            ->and(app(TokenAccess::class)->allowedFor($viewer))->toBe([TokenAccess::READ]);
    });

    it('checks what the owner may do now, not when the token was made', function () {
        $deployer = User::factory()->create(['is_super' => false]);
        $deployer->givePermissionTo(['apps.view', 'apps.deploy']);
        $plain = ($this->token)([TokenAccess::READ, TokenAccess::DEPLOY], [], $deployer);
        ($this->api)($plain)->postJson('/api/v1/apps/shop/deployments')->assertStatus(202);

        $deployer->revokePermissionTo('apps.deploy');
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        ($this->api)($plain)->postJson('/api/v1/apps/blog/deployments')->assertForbidden();
        ($this->api)($plain)->getJson('/api/v1/apps')->assertOk();
    });

    it('limits the lifetime of a token', function () {
        $short = app(TokenAccess::class)->create($this->user, 'a', [TokenAccess::READ], [], 0);
        $long = app(TokenAccess::class)->create($this->user, 'b', [TokenAccess::READ], [], 9999);

        expect(round($short->accessToken->expires_at->diffInDays(now(), true)))->toBe(1.0)
            ->and(round($long->accessToken->expires_at->diffInDays(now(), true)))->toBe(365.0);
    });
});

describe('limited to some apps', function () {
    it('shows and deploys only those, and treats the others as if they did not exist', function () {
        $plain = ($this->token)([TokenAccess::READ, TokenAccess::DEPLOY], ['shop']);

        ($this->api)($plain)->getJson('/api/v1/apps')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'shop');
        ($this->api)($plain)->getJson('/api/v1/apps/shop')->assertOk();
        ($this->api)($plain)->getJson('/api/v1/apps/blog')->assertNotFound();
        ($this->api)($plain)->postJson('/api/v1/apps/blog/deployments')->assertNotFound();
        ($this->api)($plain)->getJson('/api/v1/apps/blog/deployments/1')->assertNotFound();
        ($this->api)($plain)->postJson('/api/v1/apps/nothing/deployments')->assertNotFound();

        Queue::assertNothingPushed();
        expect(AuditLog::query()->where('action', 'api.deploy_triggered')->count())->toBe(0);
    });

    it('is not limited when no app was chosen', function () {
        ($this->api)(($this->token)())->getJson('/api/v1/apps')->assertOk()->assertJsonCount(2, 'data');
    });
});

describe('reading', function () {
    it('lists apps with their status and never a secret', function () {
        $app = $this->shop;
        $app->envVars()->create(['key' => 'SECRET_KEY', 'value' => 'hunter2']);
        Deployment::factory()->create(['app_id' => $app->id, 'number' => 1, 'status' => DeploymentStatus::Active]);
        $app->forceFill(['active_deployment_id' => Deployment::query()->where('app_id', $app->id)->value('id'), 'webhook_secret' => 'whsec_123'])->save();

        $response = ($this->api)(($this->token)([TokenAccess::READ]))->getJson('/api/v1/apps/shop')->assertOk();

        expect($response->json('data.slug'))->toBe('shop')
            ->and($response->json('data.status'))->toBe(AppStatus::Running->value)
            ->and($response->json('data.live_deployment'))->toBe(1)
            ->and($response->json('data.latest_deployment.number'))->toBe(1)
            ->and($response->getContent())->not->toContain('hunter2')->not->toContain('whsec_123');
    });

    it('does not list apps that are still being set up', function () {
        App::factory()->create(['slug' => 'draft', 'setup_step' => SetupStep::Settings]);

        ($this->api)(($this->token)())->getJson('/api/v1/apps')->assertJsonCount(2, 'data');
        ($this->api)(($this->token)())->getJson('/api/v1/apps/draft')->assertNotFound();
    });

    it('reports how a deployment went, with its log only when asked', function () {
        $d = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 7, 'status' => DeploymentStatus::Failed, 'error' => 'The build failed.', 'log' => "step 1\nstep 2"]);

        $plain = ($this->token)([TokenAccess::READ]);
        $without = ($this->api)($plain)->getJson('/api/v1/apps/shop/deployments/7')->assertOk();
        $with = ($this->api)($plain)->getJson('/api/v1/apps/shop/deployments/7?log=1')->assertOk();

        expect($without->json('data'))->toMatchArray(['number' => 7, 'status' => 'failed', 'finished' => true, 'succeeded' => false, 'error' => 'The build failed.'])
            ->and($without->json('data'))->not->toHaveKey('log')
            ->and($with->json('data.log'))->toBe("step 1\nstep 2");

        ($this->api)($plain)->getJson('/api/v1/apps/shop/deployments/99')->assertNotFound();
        expect($d->number)->toBe(7);
    });
});

describe('deploying', function () {
    it('queues a deployment like the button does, and says how to follow it', function () {
        $response = ($this->api)(($this->token)())->postJson('/api/v1/apps/shop/deployments')->assertStatus(202);

        expect($response->json('data.number'))->toBe(1)
            ->and($response->json('data.status'))->toBe('queued')
            ->and($response->json('data.url'))->toContain('/api/v1/apps/shop/deployments/1')
            ->and($this->shop->deployments()->count())->toBe(1);

        Queue::assertPushed(RunDeployment::class);

        $entry = AuditLog::query()->where('action', 'api.deploy_triggered')->first();
        expect($entry->metadata)->toBe(['app' => 'shop', 'deployment' => 1, 'token_name' => 'ci'])
            ->and($entry->user_id)->toBe($this->user->id);
    });

    it('does not start a second one while one is running', function () {
        Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Building]);

        $response = ($this->api)(($this->token)())->postJson('/api/v1/apps/shop/deployments')->assertStatus(409);

        expect($response->json('deployment.number'))->toBe(1)
            ->and($this->shop->deployments()->count())->toBe(1);
        Queue::assertNothingPushed();
    });

    it('does not deploy an app that is stopped', function () {
        $d = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Active]);
        $this->shop->forceFill(['active_deployment_id' => $d->id, 'stopped_at' => now()])->save();

        ($this->api)(($this->token)())->postJson('/api/v1/apps/shop/deployments')->assertStatus(409)->assertJsonPath('error', 'The app is stopped. Start it in CommyPanel first.');
    });
});
