<?php

use App\Auth\ActiveSessions;
use App\Auth\Permissions;
use App\Auth\SyncRolesAndPermissions;
use App\Auth\UserAgent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function sessionRow(User $user, string $id, array $extra = []): void
{
    DB::connection(config('session.connection'))->table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
        'payload' => '', 'last_activity' => time(), ...$extra,
    ]);
}

/** The rows made by sessionRow(): the test's own request session is stored in the same table. */
function sessionCount(User $user): int
{
    return DB::connection(config('session.connection'))->table('sessions')->where('user_id', $user->id)->where('ip_address', '203.0.113.9')->count();
}

function sessionKeeper(array $permissions, string $name = 'keeper'): User
{
    app(SyncRolesAndPermissions::class)();
    $role = Role::findOrCreate($name, 'web');
    $role->syncPermissions($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = User::factory()->regular()->create();
    $user->assignRole($role);

    return $user;
}

beforeEach(function () {
    // The session table normally lives in the developer's own cache database: use a scratch one.
    $this->sessionDb = tempnam(sys_get_temp_dir(), 'cp-sessions-');
    config(['session.driver' => 'database', 'database.connections.cache.database' => $this->sessionDb]);
    DB::purge('cache');
    Schema::connection('cache')->create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);
});

it('describes a user agent briefly', function () {
    expect(UserAgent::describe('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0'))->toBe(['browser' => 'Edge', 'os' => 'Windows'])
        ->and(UserAgent::describe('Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'))->toBe(['browser' => 'Firefox', 'os' => 'Linux'])
        ->and(UserAgent::describe('curl/8.0'))->toBe(['browser' => 'Script', 'os' => null])
        ->and(UserAgent::describe(null))->toBe(['browser' => null, 'os' => null]);
});

afterEach(function () {
    DB::purge('cache');
    @unlink($this->sessionDb);
});

describe('own sessions', function () {
    it('lists the signed-in browsers without ever sending a session id', function () {
        $user = User::factory()->create();
        sessionRow($user, 'abc-secret-session-id');
        sessionRow($user, 'old-one', ['last_activity' => time() - 99999]);
        $this->actingAs($user);

        $response = $this->withSession(['auth.password_confirmed_at' => time()])->get('/settings/security');

        $response->assertInertia(fn (Assert $page) => $page->component('settings/security')
            ->where('sessions.supported', true)
            ->has('sessions.list', 1)
            ->where('sessions.list.0.browser', 'Safari')
            ->where('sessions.list.0.os', 'macOS')
            ->where('sessions.list.0.ip', '203.0.113.9'));
        expect($response->getContent())->not->toContain('abc-secret-session-id');
    });

    it('signs out one other browser or all of them, and audits it', function () {
        $user = User::factory()->create();
        sessionRow($user, 'one');
        sessionRow($user, 'two');
        sessionRow($user, 'three');
        $this->actingAs($user);
        $handle = app(ActiveSessions::class)->handle('one');

        $this->delete("/settings/sessions/{$handle}")->assertRedirect();
        expect(sessionCount($user))->toBe(2);

        $this->delete('/settings/sessions')->assertRedirect();
        expect(sessionCount($user))->toBe(0)
            ->and(AuditLog::query()->whereIn('action', ['auth.session_revoked', 'auth.other_sessions_revoked'])->count())->toBe(2);
    });

    it('cannot sign out somebody else\'s browser by guessing a handle', function () {
        $me = User::factory()->create();
        $other = User::factory()->regular()->create();
        sessionRow($other, 'theirs');
        $this->actingAs($me);

        $this->delete('/settings/sessions/'.app(ActiveSessions::class)->handle('theirs'))->assertSessionHasErrors('session');
        expect(sessionCount($other))->toBe(1);
    });

    it('lists nothing and revokes nothing with another session driver', function () {
        config(['session.driver' => 'array']);
        $user = User::factory()->create();

        expect(app(ActiveSessions::class)->for($user))->toBe([])
            ->and(app(ActiveSessions::class)->revokeAll($user))->toBe(0);
    });
});

describe("other users' sessions", function () {
    it('needs the view permission, and only shows other people', function () {
        $target = User::factory()->regular()->create();
        sessionRow($target, 'x');

        $this->actingAs(sessionKeeper([Permissions::USERS_VIEW]));
        ($this->confirmed)()->get("/users/{$target->id}/sessions")->assertForbidden();

        $viewer = sessionKeeper([Permissions::SESSIONS_VIEW], 'viewer');
        $this->actingAs($viewer);
        ($this->confirmed)()->get("/users/{$target->id}/sessions")
            ->assertInertia(fn (Assert $page) => $page->component('users/sessions')->has('sessions.list', 1)->where('can_revoke', false));
        expect(AuditLog::query()->where('action', 'user.sessions_viewed')->exists())->toBeTrue();

        $this->get("/users/{$viewer->id}/sessions")->assertNotFound();
    });

    it('keeps the super user\'s sessions from everyone but the super user', function () {
        $super = User::factory()->create();
        sessionRow($super, 's');
        $this->actingAs(sessionKeeper([Permissions::SESSIONS_VIEW, Permissions::SESSIONS_REVOKE]));

        ($this->confirmed)()->get("/users/{$super->id}/sessions")->assertForbidden();
        ($this->confirmed)()->delete("/users/{$super->id}/sessions")->assertForbidden();
        expect(sessionCount($super))->toBe(1);
    });

    it('signs another user out when allowed, with a password, and audits it', function () {
        $target = User::factory()->regular()->create();
        sessionRow($target, 'a');
        sessionRow($target, 'b');
        $this->actingAs(sessionKeeper([Permissions::SESSIONS_VIEW, Permissions::SESSIONS_REVOKE]));
        $handle = app(ActiveSessions::class)->handle('a');

        $this->delete("/users/{$target->id}/sessions/{$handle}")->assertRedirect(route('password.confirm'));
        expect(sessionCount($target))->toBe(2);

        ($this->confirmed)()->delete("/users/{$target->id}/sessions/{$handle}")->assertRedirect();
        expect(sessionCount($target))->toBe(1);

        ($this->confirmed)()->delete("/users/{$target->id}/sessions")->assertRedirect();
        expect(sessionCount($target))->toBe(0)
            ->and(AuditLog::query()->whereIn('action', ['user.session_revoked', 'user.sessions_revoked'])->count())->toBe(2);
    });

    it('does not let someone sign out a user with more access', function () {
        $stronger = sessionKeeper([Permissions::SESSIONS_VIEW, Permissions::SESSIONS_REVOKE, Permissions::APPS_DELETE], 'stronger');
        sessionRow($stronger, 'x');
        $this->actingAs(sessionKeeper([Permissions::SESSIONS_VIEW, Permissions::SESSIONS_REVOKE], 'weaker'));

        ($this->confirmed)()->delete("/users/{$stronger->id}/sessions")->assertForbidden();
        expect(sessionCount($stronger))->toBe(1);
    });
});

describe('audit export', function () {
    it('needs the permission and a password, and is itself audited', function () {
        $this->actingAs(sessionKeeper([Permissions::AUDIT_VIEW]));
        ($this->confirmed)()->get('/settings/audit-log/export?format=csv')->assertForbidden();

        $this->flushSession();
        $this->actingAs(sessionKeeper([Permissions::AUDIT_EXPORT], 'exporter'));
        $this->get('/settings/audit-log/export?format=csv')->assertRedirect(route('password.confirm'));

        ($this->confirmed)()->get('/settings/audit-log/export?format=csv')->assertOk();
        expect(AuditLog::query()->where('action', 'audit.exported')->exists())->toBeTrue();
    });

    it('exports the filtered rows as CSV and neutralises spreadsheet formulas', function () {
        $user = User::factory()->create();
        AuditLog::query()->create(['user_id' => $user->id, 'user_email' => '=cmd@example.com', 'action' => 'app.deleted', 'metadata' => ['app' => 'shop'], 'ip_address' => '203.0.113.1']);
        AuditLog::query()->create(['user_id' => $user->id, 'user_email' => 'a@example.com', 'action' => 'app.deployed', 'ip_address' => '203.0.113.2']);
        $this->actingAs($user);

        $csv = ($this->confirmed)()->get('/settings/audit-log/export?format=csv&severity[]=danger')->streamedContent();

        expect($csv)->toContain('time,severity,user,action,ip,user_agent,details')
            ->toContain('app.deleted')->toContain("'=cmd@example.com")
            ->not->toContain('app.deployed');
    });

    it('exports valid JSON and refuses an unknown format', function () {
        $user = User::factory()->create();
        AuditLog::query()->create(['user_id' => $user->id, 'user_email' => 'a@example.com', 'action' => 'app.deleted']);
        $this->actingAs($user);

        $json = ($this->confirmed)()->get('/settings/audit-log/export?format=json')->streamedContent();
        $rows = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        expect($rows)->toBeArray()->and(collect($rows)->pluck('action'))->toContain('app.deleted')
            ->and($rows[0]['severity'])->toBeIn(['danger', 'warning', 'info']);

        ($this->confirmed)()->get('/settings/audit-log/export?format=xml')->assertSessionHasErrors('format');
    });
});
