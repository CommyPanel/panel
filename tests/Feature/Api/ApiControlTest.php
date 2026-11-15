<?php

use App\Api\TokenAccess;
use App\Audit\Severity;
use App\Auth\SyncRolesAndPermissions;
use App\Enums\DeploymentStatus;
use App\Host\Data\ContainerSpec;
use App\Host\HostDriver;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\AppEnvVar;
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
    $this->host = app(HostDriver::class);

    $this->live = function (string $slug = 'shop'): App {
        $app = App::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $deployment = Deployment::factory()->create([
            'app_id' => $app->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'host_port' => 20001,
            'container_name' => $slug.'-1', 'image_tag' => 'commypanel/'.$slug.':1', 'image_available' => true,
        ]);
        $app->update(['active_deployment_id' => $deployment->id]);
        $this->host->runContainer(new ContainerSpec($slug.'-1', 'commypanel/'.$slug.':1', 20001, 8080));

        return $app->refresh();
    };
    $this->token = fn (array $abilities, array $apps = [], ?User $user = null) => app(TokenAccess::class)->create($user ?? $this->user, 'ci', $abilities, $apps, 30)->plainTextToken;
    $this->api = function (string $token) {
        app('auth')->forgetGuards();

        return $this->withToken($token)->withHeaders(['Accept' => 'application/json']);
    };
});

describe('the token itself', function () {
    it('says what it is, with no ability needed and nothing secret', function () {
        $plain = ($this->token)([TokenAccess::READ], ['shop']);

        $response = ($this->api)($plain)->getJson('/api/v1/token')->assertOk();

        expect($response->json('data'))->toMatchArray(['name' => 'ci', 'abilities' => ['apps:read'], 'apps' => ['shop']])
            ->and($response->json('data'))->toHaveKeys(['expires_at', 'last_used_at', 'owner'])
            ->and($response->getContent())->not->toContain($plain)->not->toContain('@');
    });

    it('says apps is null for a token that may use every app', function () {
        ($this->api)(($this->token)([TokenAccess::READ]))->getJson('/api/v1/token')->assertJsonPath('data.apps', null);
    });

    it('wants a token like every other call', function () {
        $this->getJson('/api/v1/token')->assertUnauthorized()->assertJsonStructure(['error']);
    });
});

describe('deployments list', function () {
    it('lists the newest first and respects the limit', function () {
        $app = ($this->live)();
        Deployment::factory()->create(['app_id' => $app->id, 'number' => 2, 'status' => DeploymentStatus::Failed]);
        Deployment::factory()->create(['app_id' => $app->id, 'number' => 3, 'status' => DeploymentStatus::Failed]);
        $api = ($this->api)(($this->token)([TokenAccess::READ]));

        $api->getJson('/api/v1/apps/shop/deployments')->assertOk()->assertJsonPath('data.0.number', 3)->assertJsonCount(3, 'data');
        $api->getJson('/api/v1/apps/shop/deployments?limit=1')->assertJsonCount(1, 'data');
    });

    it('is not open to a token limited to other apps', function () {
        ($this->live)();
        ($this->live)('blog');

        ($this->api)(($this->token)([TokenAccess::READ], ['blog']))->getJson('/api/v1/apps/shop/deployments')->assertNotFound();
    });
});

describe('start, stop and restart', function () {
    it('stops, starts and restarts the live container, and says who did it', function () {
        ($this->live)();
        $api = ($this->api)(($this->token)([TokenAccess::CONTROL]));

        $api->postJson('/api/v1/apps/shop/stop')->assertOk()->assertJsonPath('data.status', 'stopped');
        $api->postJson('/api/v1/apps/shop/start')->assertOk()->assertJsonPath('data.status', 'running');
        $api->postJson('/api/v1/apps/shop/restart')->assertOk();

        $log = AuditLog::query()->where('action', 'app.stopped')->sole();
        expect($log->metadata)->toMatchArray(['app' => 'shop', 'via_token' => 'ci'])
            ->and(AuditLog::query()->whereIn('action', ['app.started', 'app.restarted'])->count())->toBe(2);
    });

    it('answers 409 with a reason when the state does not allow it', function () {
        ($this->live)();
        $api = ($this->api)(($this->token)([TokenAccess::CONTROL]));

        $api->postJson('/api/v1/apps/shop/start')->assertStatus(409)->assertJsonStructure(['error']);
        $api->postJson('/api/v1/apps/shop/stop')->assertOk();
        $api->postJson('/api/v1/apps/shop/stop')->assertStatus(409);
        $api->postJson('/api/v1/apps/shop/restart')->assertStatus(409);
    });

    it('waits while a deployment runs', function () {
        $app = ($this->live)();
        Deployment::factory()->create(['app_id' => $app->id, 'number' => 2, 'status' => DeploymentStatus::Building]);

        ($this->api)(($this->token)([TokenAccess::CONTROL]))->postJson('/api/v1/apps/shop/restart')->assertStatus(409);
    });

    it('needs its own ability, the permission of the owner, and the app to be allowed', function () {
        ($this->live)();
        ($this->live)('blog');

        ($this->api)(($this->token)([TokenAccess::READ, TokenAccess::DEPLOY]))->postJson('/api/v1/apps/shop/stop')->assertForbidden();
        ($this->api)(($this->token)([TokenAccess::CONTROL], ['blog']))->postJson('/api/v1/apps/shop/stop')->assertNotFound();

        $role = Role::findOrCreate('viewer', 'web');
        $role->syncPermissions(['apps.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $other = User::factory()->regular()->create();
        $other->assignRole($role);
        // The ability alone is not enough: the owner may not control apps.
        $token = $other->createToken('x', [TokenAccess::CONTROL])->plainTextToken;
        ($this->api)($token)->postJson('/api/v1/apps/shop/stop')->assertForbidden();
    });
});

describe('logs', function () {
    it('reads the live container\'s output, clamps the length and audits the read', function () {
        ($this->live)();
        $api = ($this->api)(($this->token)([TokenAccess::LOGS]));

        $api->getJson('/api/v1/apps/shop/logs')->assertOk()
            ->assertJsonPath('data.live', true)->assertJsonPath('data.lines', 200)
            ->assertJsonPath('data.logs', "[fake] logs for shop-1\n");
        $api->getJson('/api/v1/apps/shop/logs?tail=99999')->assertJsonPath('data.lines', 5000);
        $api->getJson('/api/v1/apps/shop/logs?tail=1')->assertJsonPath('data.lines', 10);

        $log = AuditLog::query()->where('action', 'api.logs_read')->latest('id')->first();
        expect($log->metadata)->toMatchArray(['app' => 'shop', 'via_token' => 'ci'])
            ->and(Severity::of('api.logs_read'))->toBe(Severity::WARNING);
    });

    it('says there is nothing when the app has no running container', function () {
        App::factory()->create(['slug' => 'idle']);

        ($this->api)(($this->token)([TokenAccess::LOGS]))->getJson('/api/v1/apps/idle/logs')->assertOk()
            ->assertJsonPath('data.live', false)->assertJsonPath('data.logs', '');
    });

    it('needs its own ability: reading and deploying are not enough', function () {
        ($this->live)();

        ($this->api)(($this->token)([TokenAccess::READ, TokenAccess::DEPLOY, TokenAccess::ENV]))->getJson('/api/v1/apps/shop/logs')->assertForbidden();
    });
});

describe('environment variables', function () {
    it('lists names only, never values', function () {
        $app = ($this->live)();
        AppEnvVar::query()->create(['app_id' => $app->id, 'key' => 'DB_PASSWORD', 'value' => 'hunter2-secret']);
        AppEnvVar::query()->create(['app_id' => $app->id, 'key' => 'APP_ENV', 'value' => 'production']);

        $response = ($this->api)(($this->token)([TokenAccess::ENV]))->getJson('/api/v1/apps/shop/env')->assertOk();

        expect($response->json('data.keys'))->toBe(['APP_ENV', 'DB_PASSWORD'])
            ->and($response->json('data'))->toHaveKey('pending_changes')
            ->and($response->getContent())->not->toContain('hunter2-secret')->not->toContain('production');
    });

    it('sets a variable (201 when new, 200 when replaced) without ever echoing the value', function () {
        $app = ($this->live)();
        $api = ($this->api)(($this->token)([TokenAccess::ENV]));

        $created = $api->putJson('/api/v1/apps/shop/env/STRIPE_KEY', ['value' => 'sk_live_topsecret'])->assertCreated();
        $replaced = $api->putJson('/api/v1/apps/shop/env/STRIPE_KEY', ['value' => 'sk_live_other'])->assertOk();

        expect($app->envVars()->where('key', 'STRIPE_KEY')->first()->value)->toBe('sk_live_other')
            ->and($created->getContent())->not->toContain('topsecret')
            ->and($replaced->getContent())->not->toContain('sk_live_other');

        $log = AuditLog::query()->where('action', 'app.env_set')->latest('id')->first();
        expect($log->metadata)->toMatchArray(['key' => 'STRIPE_KEY', 'via_token' => 'ci'])
            ->and(json_encode($log->metadata))->not->toContain('sk_live');
    });

    it('refuses bad names, PORT and a missing value, in the API\'s error shape', function () {
        ($this->live)();
        $api = ($this->api)(($this->token)([TokenAccess::ENV]));

        $api->putJson('/api/v1/apps/shop/env/PORT', ['value' => '1'])->assertUnprocessable()->assertJsonStructure(['error', 'errors' => ['key']]);
        $api->putJson('/api/v1/apps/shop/env/1BAD', ['value' => '1'])->assertNotFound(); // not a valid name: no such route
        $api->putJson('/api/v1/apps/shop/env/GOOD')->assertUnprocessable()->assertJsonStructure(['error', 'errors' => ['value']]);
    });

    it('removes a variable, and says 404 for one that is not there', function () {
        $app = ($this->live)();
        AppEnvVar::query()->create(['app_id' => $app->id, 'key' => 'GONE', 'value' => 'x']);
        $api = ($this->api)(($this->token)([TokenAccess::ENV]));

        $api->deleteJson('/api/v1/apps/shop/env/GONE')->assertOk()->assertJsonPath('data.key', 'GONE');
        $api->deleteJson('/api/v1/apps/shop/env/GONE')->assertNotFound();
        expect(AuditLog::query()->where('action', 'app.env_removed')->count())->toBe(1);
    });

    it('needs its own ability and the apps limit', function () {
        ($this->live)();
        ($this->live)('blog');

        ($this->api)(($this->token)([TokenAccess::READ, TokenAccess::LOGS]))->getJson('/api/v1/apps/shop/env')->assertForbidden();
        ($this->api)(($this->token)([TokenAccess::ENV], ['blog']))->putJson('/api/v1/apps/shop/env/A', ['value' => '1'])->assertNotFound();
    });
});

describe('apply changes', function () {
    it('queues a settings-only deployment from the running version', function () {
        ($this->live)();

        $response = ($this->api)(($this->token)([TokenAccess::DEPLOY]))->postJson('/api/v1/apps/shop/apply')->assertStatus(202);

        expect($response->json('data.number'))->toBe(2)->and($response->json('data.finished'))->toBeFalse();
        Queue::assertPushed(RunDeployment::class);
    });

    it('says 409 when there is nothing running to apply to, or a deployment is busy', function () {
        App::factory()->create(['slug' => 'idle']);
        ($this->api)(($this->token)([TokenAccess::DEPLOY]))->postJson('/api/v1/apps/idle/apply')->assertStatus(409)->assertJsonStructure(['error']);

        $app = ($this->live)();
        Deployment::factory()->create(['app_id' => $app->id, 'number' => 2, 'status' => DeploymentStatus::Building]);
        ($this->api)(($this->token)([TokenAccess::DEPLOY]))->postJson('/api/v1/apps/shop/apply')->assertStatus(409)->assertJsonStructure(['error', 'deployment']);
    });

    it('needs the deploy ability', function () {
        ($this->live)();

        ($this->api)(($this->token)([TokenAccess::READ, TokenAccess::ENV]))->postJson('/api/v1/apps/shop/apply')->assertForbidden();
    });
});

describe('one error shape', function () {
    it('gives every JSON error an error sentence', function () {
        ($this->live)();
        // Without a token first (a token set on the test stays for the calls after it).
        $this->getJson('/api/v1/apps')->assertUnauthorized()->assertJsonStructure(['error']);
        expect($this->getJson('/api/v1/nowhere')->assertNotFound()->json('error'))->toBeString();

        $api = ($this->api)(($this->token)([TokenAccess::READ]));
        $api->getJson('/api/v1/apps/nope')->assertNotFound()->assertJsonStructure(['error']);
        $api->postJson('/api/v1/apps/shop/deployments')->assertForbidden()->assertJsonStructure(['error']);
    });
});
