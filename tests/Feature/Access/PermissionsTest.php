<?php

use App\Actions\Setup\IssueSetupToken;
use App\Auth\Permissions;
use App\Auth\SyncRolesAndPermissions;
use App\Models\App;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function regularUser(array $permissions = []): User
{
    app(SyncRolesAndPermissions::class)();
    $user = User::factory()->regular()->create();

    if ($permissions !== []) {
        $role = Role::findOrCreate('custom-'.bin2hex(random_bytes(3)), 'web');
        $role->syncPermissions($permissions);
        $user->assignRole($role);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

describe('first setup', function () {
    it('makes the first account the super user and seeds permissions and the admin role', function () {
        $token = app(IssueSetupToken::class)();

        $this->post("/setup/{$token}", [
            'name' => 'Admin', 'email' => 'admin@example.com',
            'password' => 'a-long-password-123', 'password_confirmation' => 'a-long-password-123',
        ])->assertRedirect('/welcome');

        $user = User::query()->firstOrFail();
        $admin = Role::findByName('admin', 'web');

        expect($user->isSuper())->toBeTrue()
            ->and(Permission::query()->count())->toBe(count(Permissions::names()))
            ->and($admin->permissions->count())->toBe(count(Permissions::names()))
            ->and($admin->users()->count())->toBe(0)
            ->and($user->roles)->toHaveCount(0);
    });

    it('does not let the super flag be set through mass assignment', function () {
        $user = User::query()->create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-password-1', 'is_super' => true]);

        expect($user->fresh()->isSuper())->toBeFalse();
    });
});

describe('syncing', function () {
    it('is safe to repeat and keeps admin holding every permission', function () {
        app(SyncRolesAndPermissions::class)();
        app(SyncRolesAndPermissions::class)();

        expect(Permission::query()->count())->toBe(count(Permissions::names()))
            ->and(Role::query()->where('name', 'admin')->count())->toBe(1);
    });

    it('adds new permissions to admin and drops ones that no longer exist', function () {
        app(SyncRolesAndPermissions::class)();
        Permission::findOrCreate('legacy.thing', 'web');
        Role::findByName('admin', 'web')->givePermissionTo('legacy.thing');
        Permission::query()->where('name', Permissions::AUDIT_VIEW)->first()->delete();

        app(SyncRolesAndPermissions::class)();

        $names = Role::findByName('admin', 'web')->permissions->pluck('name')->all();
        expect($names)->toContain(Permissions::AUDIT_VIEW)->not->toContain('legacy.thing');
    });

    it('runs with the migrate command, so an upgrade picks up new permissions', function () {
        $this->artisan('commypanel:migrate', ['--force' => true])->assertSuccessful();

        expect(Permission::query()->count())->toBe(count(Permissions::names()));
    });

    it('describes every permission', function () {
        foreach (Permissions::definitions() as $name => $definition) {
            expect($definition['group'])->not->toBe('')
                ->and($definition['label'])->not->toBe('')
                ->and(strlen($definition['description']))->toBeGreaterThan(20, "{$name} needs an explanation");
        }
    });
});

describe('route protection', function () {
    beforeEach(function () {
        $shop = App::factory()->create(['slug' => 'shop']);
        Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $shop->id]);
        // Bound by id, so it has to exist for the route to reach its permission check.
        $shop->volumes()->create(['name' => 'data', 'path' => '/data']);
    });

    $routes = [
        'apps.view' => [['get', '/apps'], ['get', '/apps/shop/usage'], ['get', '/apps/shop/containers']],
        'apps.create' => [['get', '/apps/create'], ['post', '/apps']],
        'apps.update' => [['patch', '/apps/shop'], ['post', '/apps/shop/deploy-key'], ['post', '/apps/shop/git-token'], ['delete', '/apps/shop/git-token'], ['post', '/apps/shop/webhook'], ['delete', '/apps/shop/webhook'], ['post', '/apps/shop/volumes'], ['put', '/apps/shop/lock'], ['put', '/apps/shop/volumes/1/lock'], ['delete', '/apps/shop/volumes/1'], ['delete', '/apps/shop/volumes/uploads/data']],
        'apps.deploy' => [['post', '/apps/shop/deployments']],
        'apps.nginx' => [['get', '/apps/shop/nginx'], ['put', '/apps/shop/nginx']],
        'apps.volumes' => [['get', '/apps/shop/volumes'], ['get', '/apps/shop/volumes/data/browse'], ['get', '/apps/shop/volumes/data/file'], ['get', '/apps/shop/volumes/data/download']],
        'apps.terminal' => [['post', '/apps/shop/terminal']],
        'apps.logs' => [['get', '/apps/shop/logs'], ['get', '/apps/shop/logs/download']],
        'apps.control' => [['post', '/apps/shop/stop'], ['post', '/apps/shop/start'], ['post', '/apps/shop/restart']],
        'apps.deactivate' => [['post', '/apps/shop/deactivate'], ['post', '/apps/shop/activate']],
        'apps.delete' => [['delete', '/apps/shop']],
        'env.manage' => [['post', '/apps/shop/env'], ['post', '/apps/shop/env/import'], ['delete', '/apps/shop/env/KEY']],
        'domains.view' => [['get', '/domains']],
        'domains.manage' => [['post', '/apps/shop/domains'], ['post', '/apps/shop/certificate']],
        'domains.settings' => [['get', '/welcome'], ['put', '/settings/https'], ['put', '/settings/server-address'], ['post', '/settings/server-address/detect']],
        'server.view' => [['get', '/server'], ['get', '/server/docker'], ['get', '/server/nginx'], ['get', '/server/usage'], ['get', '/settings/updates']],
        'server.manage' => [['post', '/server/nginx/reload'], ['post', '/server/nginx/test'], ['post', '/server/docker/prune'], ['get', '/server/docker/containers/shop-1/logs'], ['post', '/server/docker/restart'], ['post', '/settings/updates/check'], ['put', '/settings/updates']],
        'users.view' => [['get', '/users']],
        'users.create' => [['get', '/users/create'], ['post', '/users']],
        'users.update' => [['get', '/users/1/edit'], ['put', '/users/1']],
        'users.delete' => [['delete', '/users/1']],
        'roles.view' => [['get', '/roles']],
        'roles.manage' => [['get', '/roles/create'], ['post', '/roles']],
        'permissions.view' => [['get', '/permissions']],
        'audit.view' => [['get', '/settings/audit-log']],
    ];

    it('refuses a signed-in user without a role on every protected route', function (string $permission, string $method, string $url) {
        $this->actingAs(regularUser());

        $this->{$method}($url)->assertForbidden();
    })->with(collect($routes)->flatMap(fn ($list, $permission) => collect($list)->mapWithKeys(fn ($r) => ["{$permission}: {$r[0]} {$r[1]}" => [$permission, $r[0], $r[1]]]))->all());

    it('refuses a user whose role grants some other permission', function (string $permission, string $method, string $url) {
        $other = collect(Permissions::names())->first(fn ($name) => $name !== $permission);
        $this->actingAs(regularUser([$other]));

        $this->{$method}($url)->assertForbidden();
    })->with(collect($routes)->flatMap(fn ($list, $permission) => collect($list)->mapWithKeys(fn ($r) => ["{$permission}: {$r[0]} {$r[1]}" => [$permission, $r[0], $r[1]]]))->all());

    it('lets a user through when their role grants the permission', function (string $permission, string $method, string $url) {
        $this->actingAs(regularUser([$permission]));

        // Whatever the page then does, it must not be a permission refusal.
        expect($this->{$method}($url)->status())->not->toBe(403);
    })->with(collect($routes)->flatMap(fn ($list, $permission) => collect($list)->mapWithKeys(fn ($r) => ["{$permission}: {$r[0]} {$r[1]}" => [$permission, $r[0], $r[1]]]))->all());

    it('refuses editing and deleting roles without roles.manage', function () {
        $role = Role::findOrCreate('support', 'web');
        $this->actingAs(regularUser([Permissions::ROLES_VIEW]));

        $this->put("/roles/{$role->id}", ['name' => 'x', 'permissions' => []])->assertForbidden();
        $this->delete("/roles/{$role->id}")->assertForbidden();
        $this->get("/roles/{$role->id}/edit")->assertForbidden();
    });

    it('lets the super user through everywhere', function () {
        $this->actingAs(User::factory()->create());

        $this->get('/apps')->assertOk();
        $this->get('/users')->assertOk();
        $this->get('/roles')->assertOk();
        $this->get('/permissions')->assertOk();
    });

    it('keeps the dashboard and account settings open to every signed-in user', function () {
        $this->actingAs(regularUser());

        $this->get('/dashboard')->assertOk();
        $this->get('/settings/appearance')->assertOk();
    });

    it('shares the permissions with the pages', function () {
        $this->actingAs(regularUser([Permissions::APPS_VIEW, Permissions::SERVER_VIEW]));

        $this->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('auth.isSuper', false)
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === [Permissions::APPS_VIEW, Permissions::SERVER_VIEW]));
    });
});

describe('admin:reset', function () {
    it('resets the super user when no email is given', function () {
        $super = User::factory()->create();
        $other = User::factory()->regular()->create();

        $this->artisan('admin:reset')->assertSuccessful();

        expect(Hash::check('password', $super->fresh()->password))->toBeFalse()
            ->and(Hash::check('password', $other->fresh()->password))->toBeTrue();
    });

    it('says so when there is no super user yet', function () {
        $this->artisan('admin:reset')->assertFailed();
    });
});
