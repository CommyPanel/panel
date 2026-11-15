<?php

use App\Audit\Severity;
use App\Auth\Permissions;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('asks for the password before showing the key', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/settings/encryption-key')->assertRedirect(route('password.confirm'));
    expect(AuditLog::query()->where('action', 'server.key_viewed')->count())->toBe(0);
});

it('shows the key after confirmation and audits the visit as danger', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    $this->actingAs(User::factory()->create());

    $r = $this->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/encryption-key');
    $r->assertInertia(fn (Assert $page) => $page
        ->component('settings/encryption-key')
        ->where('key', config('app.key'))
        ->where('setup', false));

    $log = AuditLog::query()->where('action', 'server.key_viewed')->sole();
    expect(Severity::of($log->action))->toBe('danger');
});

it('offers the first-run version of the page', function () {
    $this->actingAs(User::factory()->create());

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/encryption-key?setup=1')
        ->assertInertia(fn (Assert $page) => $page->where('setup', true));
});

it('refuses a user without the permission', function () {
    $this->actingAs(User::factory()->create(['is_super' => false]));

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/encryption-key')->assertForbidden();
});

it('lets the first-run page know whether the key step follows', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/welcome')->assertInertia(fn (Assert $page) => $page->where('can_see_key', true));

    $this->actingAs(keyHolderWithDomains());
    $this->get('/welcome')->assertInertia(fn (Assert $page) => $page->where('can_see_key', false));
});

function keyHolderWithDomains(): User
{
    $user = User::factory()->create(['is_super' => false]);
    $role = Role::findOrCreate('domains', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::DOMAINS_SETTINGS, 'web'));
    $user->assignRole($role);

    return $user;
}
