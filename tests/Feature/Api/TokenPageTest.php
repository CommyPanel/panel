<?php

use App\Api\TokenAccess;
use App\Auth\Permissions;
use App\Auth\SyncRolesAndPermissions;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app(SyncRolesAndPermissions::class)();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);
    App::factory()->create(['slug' => 'shop', 'name' => 'Shop']);
    App::factory()->create(['slug' => 'blog', 'name' => 'Blog']);
});

function validToken(array $over = []): array
{
    return ['name' => 'GitHub Actions', 'abilities' => [TokenAccess::READ, TokenAccess::DEPLOY], 'apps' => [], 'days' => 90, ...$over];
}

it('shows a person their own tokens and what they may put on one', function () {
    app(TokenAccess::class)->create($this->user, 'mine', [TokenAccess::READ], ['shop'], 30);
    app(TokenAccess::class)->create(User::factory()->create(), 'somebody elses', [TokenAccess::READ], [], 30);

    $this->get('/settings/tokens')->assertInertia(fn (Assert $page) => $page
        ->component('settings/tokens')
        ->has('tokens', 1)
        ->where('tokens.0.name', 'mine')
        ->where('tokens.0.abilities', ['Read apps and deployments'])
        ->where('tokens.0.apps', ['shop'])
        ->where('tokens.0.expired', false)
        ->where('can_create', true)
        ->where('new_token', null)
        ->missing('tokens.0.token'));

    $this->get('/settings/tokens/create')->assertInertia(fn (Assert $page) => $page
        ->component('settings/token-create')
        ->has('abilities', 2)
        ->where('apps', fn ($apps) => collect($apps)->pluck('slug')->sort()->values()->all() === ['blog', 'shop'])
        ->where('default_days', 90));
});

it('offers a person only the abilities they hold', function () {
    $viewer = User::factory()->regular()->create();
    $viewer->givePermissionTo('apps.view', 'tokens.manage');

    $this->actingAs($viewer)->get('/settings/tokens/create')->assertInertia(fn (Assert $page) => $page
        ->has('abilities', 1)
        ->where('abilities.0.key', TokenAccess::READ));

    $nobody = User::factory()->regular()->create();
    $nobody->givePermissionTo('tokens.manage');
    $this->actingAs($nobody)->get('/settings/tokens/create')->assertInertia(fn (Assert $page) => $page->has('abilities', 0)->where('apps', []));
    $this->actingAs($nobody)->get('/settings/tokens')->assertInertia(fn (Assert $page) => $page->where('can_create', false));
});

describe('making one', function () {
    it('asks for the password, then shows the token once and never again', function () {
        $this->post('/settings/tokens', validToken())->assertRedirect();
        expect($this->user->tokens()->count())->toBe(0);

        $response = ($this->confirmed)()->post('/settings/tokens', validToken())->assertRedirect('/settings/tokens');
        $response->assertSessionHas('new_token');

        $shown = session('new_token')['token'];
        expect($shown)->toMatch('/^\d+\|cpt_/');

        $this->get('/settings/tokens')->assertInertia(fn (Assert $page) => $page
            ->where('new_token.name', 'GitHub Actions')
            ->where('new_token.token', $shown));

        $this->get('/settings/tokens')->assertInertia(fn (Assert $page) => $page->where('new_token', null));

        // And it works against the API.
        app('auth')->forgetGuards();
        $this->withToken($shown)->getJson('/api/v1/apps')->assertOk()->assertJsonCount(2, 'data');
    });

    it('records it without the token', function () {
        ($this->confirmed)()->post('/settings/tokens', validToken(['apps' => ['shop']]));

        $entry = AuditLog::query()->where('action', 'token.created')->first();

        expect($entry->metadata['name'])->toBe('GitHub Actions')
            ->and($entry->metadata['abilities'])->toBe([TokenAccess::READ, TokenAccess::DEPLOY, 'app:shop'])
            ->and($entry->metadata['expires_at'])->not->toBeNull()
            ->and(json_encode($entry->metadata))->not->toContain('cpt_');
    });

    it('limits it to the apps chosen', function () {
        ($this->confirmed)()->post('/settings/tokens', validToken(['apps' => ['shop']]));

        $token = $this->user->tokens()->first();
        expect(app(TokenAccess::class)->appsOf($token))->toBe(['shop']);
    });

    it('refuses what the person may not do, an unknown app, a bad lifetime and an empty choice', function () {
        $viewer = User::factory()->regular()->create();
        $viewer->givePermissionTo('apps.view', 'tokens.manage');
        $this->actingAs($viewer);

        ($this->confirmed)()->post('/settings/tokens', validToken())->assertSessionHasErrors('abilities.1');

        $this->actingAs($this->user);
        ($this->confirmed)()->post('/settings/tokens', validToken(['apps' => ['nothing']]))->assertSessionHasErrors('apps.0');
        ($this->confirmed)()->post('/settings/tokens', validToken(['days' => 0]))->assertSessionHasErrors('days');
        ($this->confirmed)()->post('/settings/tokens', validToken(['days' => 366]))->assertSessionHasErrors('days');
        ($this->confirmed)()->post('/settings/tokens', validToken(['abilities' => []]))->assertSessionHasErrors('abilities');
        ($this->confirmed)()->post('/settings/tokens', validToken(['name' => '']))->assertSessionHasErrors('name');

        expect($this->user->tokens()->count())->toBe(0);
    });

    it('stops at twenty', function () {
        for ($i = 0; $i < 20; $i++) {
            app(TokenAccess::class)->create($this->user, 't'.$i, [TokenAccess::READ], [], 30);
        }

        ($this->confirmed)()->post('/settings/tokens', validToken())->assertSessionHasErrors('name');

        expect($this->user->tokens()->count())->toBe(20);
    });
});

describe('revoking one', function () {
    it('stops it working at once, after the password, and records it', function () {
        $plain = app(TokenAccess::class)->create($this->user, 'old', [TokenAccess::READ], [], 30);
        $id = $plain->accessToken->id;

        $this->delete("/settings/tokens/{$id}")->assertRedirect();
        expect($this->user->tokens()->count())->toBe(1);

        ($this->confirmed)()->delete("/settings/tokens/{$id}")->assertSessionHas('status');

        expect($this->user->tokens()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'token.revoked')->first()->metadata)->toBe(['name' => 'old']);

        app('auth')->forgetGuards();
        $this->withToken($plain->plainTextToken)->getJson('/api/v1/apps')->assertUnauthorized();
    });

    it('cannot revoke somebody else\'s token', function () {
        $other = User::factory()->create();
        $theirs = app(TokenAccess::class)->create($other, 'theirs', [TokenAccess::READ], [], 30)->accessToken->id;

        ($this->confirmed)()->delete("/settings/tokens/{$theirs}")->assertNotFound();

        expect($other->tokens()->count())->toBe(1);
    });
});

it('is for people who are signed in', function () {
    auth()->logout();

    $this->get('/settings/tokens')->assertRedirect('/login');
    $this->post('/settings/tokens', validToken())->assertRedirect('/login');
});

it('needs the tokens permission for the page, creating and revoking', function () {
    $nobody = User::factory()->regular()->create();
    $this->actingAs($nobody);

    $this->get('/settings/tokens')->assertForbidden();
    $this->get('/settings/tokens/create')->assertForbidden();
    ($this->confirmed)()->post('/settings/tokens', validToken())->assertForbidden();
    $this->delete('/settings/tokens/1')->assertForbidden();

    $nobody->givePermissionTo('tokens.manage', 'apps.view');
    $this->get('/settings/tokens')->assertOk();
});

it('lists the permission with the others, for roles to grant', function () {
    expect(Permissions::names())->toContain('tokens.manage')
        ->and(Role::findByName('admin')->hasPermissionTo('tokens.manage'))->toBeTrue();
});

describe('a token that never expires', function () {
    it('can be made, is recorded as such, and keeps working', function () {
        $response = ($this->confirmed)()->post('/settings/tokens', validToken(['days' => 'never']))->assertRedirect('/settings/tokens');
        $plain = session('new_token')['token'];
        $token = $this->user->tokens()->firstOrFail();

        expect($token->expires_at)->toBeNull()
            ->and(AuditLog::query()->where('action', 'token.created')->first()->metadata['never_expires'])->toBeTrue();

        $this->get('/settings/tokens')->assertInertia(fn (Assert $page) => $page->where('tokens.0.expires_at', null)->where('tokens.0.expired', false));

        Carbon::setTestNow(now()->addYears(5));
        app('auth')->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/apps')->assertOk();
        Carbon::setTestNow();
    });

    it('is not cut short by a global limit: a one-year token works after 100 days', function () {
        ($this->confirmed)()->post('/settings/tokens', validToken(['days' => 365]));
        $plain = session('new_token')['token'];

        Carbon::setTestNow(now()->addDays(100));
        app('auth')->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/apps')->assertOk();
        Carbon::setTestNow(now()->addDays(300));
        app('auth')->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/apps')->assertUnauthorized();
        Carbon::setTestNow();
    });

    it('still refuses a lifetime that is neither a number of days nor never', function (mixed $days) {
        ($this->confirmed)()->post('/settings/tokens', validToken(['days' => $days]))->assertSessionHasErrors('days');
        expect($this->user->tokens()->count())->toBe(0);
    })->with(['zero' => 0, 'too long' => 400, 'words' => 'forever', 'decimal' => '1.5', 'negative' => -5, 'empty' => '']);

    it('is gone with its owner', function () {
        $owner = User::factory()->regular()->create();
        app(TokenAccess::class)->create($owner, 'forever', [TokenAccess::READ], [], null);
        app(TokenAccess::class)->create($owner, 'dated', [TokenAccess::READ], [], 30);
        $other = app(TokenAccess::class)->create($this->user, 'mine', [TokenAccess::READ], [], null);

        $owner->delete();

        expect(PersonalAccessToken::query()->count())->toBe(1)
            ->and(PersonalAccessToken::query()->first()->name)->toBe('mine');
    });
});
