<?php

use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Databases\UserPrivileges;
use App\Enums\DatabaseEngine;
use App\Models\App;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The Permissions card of a database server: what its users may do, as CommyPanel grants it.
 */
describe('the privileges of a user', function () {
    it('lists, for MySQL and MariaDB, everything on its own database and no rights over the server', function (DatabaseEngine $engine) {
        $granted = UserPrivileges::for($engine, false);

        expect(array_keys($granted))->toBe([...UserPrivileges::DATABASE_KEYS, 'create_user', 'show_databases', 'grant_option'])
            ->and(array_keys(array_filter($granted)))->toBe(UserPrivileges::DATABASE_KEYS);
    })->with([DatabaseEngine::Mysql, DatabaseEngine::Mariadb]);

    it('gives the administrator of a MySQL server every privilege, with the right to pass them on', function () {
        expect(UserPrivileges::for(DatabaseEngine::Mysql, true))->each->toBeTrue();
    });

    it('lists the PostgreSQL privileges: log in and own the database, and for an administrator also create databases and roles', function () {
        expect(UserPrivileges::for(DatabaseEngine::Postgres, false))->toBe(['login' => true, 'database_owner' => true, 'create_databases' => false, 'create_roles' => false])
            ->and(UserPrivileges::for(DatabaseEngine::Postgres, true))->toBe(['login' => true, 'database_owner' => true, 'create_databases' => true, 'create_roles' => true]);
    });
});

describe('the server page', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
    });

    it('shows each user with its scope and what it may do, and no password', function () {
        $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17')->fresh();
        $database = app(ManageDatabases::class)->create($server, 'shop');

        $response = $this->get('/databases/main')->assertInertia(fn (Assert $page) => $page
            ->where('permissions.privileges', ['login', 'database_owner', 'create_databases', 'create_roles'])
            ->where('permissions.whole_server', false)
            ->where('permissions.users.0.user', 'shop')
            ->where('permissions.users.0.database', 'shop')
            ->where('permissions.users.0.scope', 'database')
            ->where('permissions.users.0.granted.create_databases', false));

        expect($response->getContent())->not->toContain($database->password);
    });

    it('marks the user of a server made for one app as able to manage the whole server', function () {
        $app = App::factory()->create(['slug' => 'blog']);
        $server = app(DatabaseServers::class)->create('Blog db', DatabaseEngine::Mysql, '8.4')->fresh();
        $server->forceFill(['dedicated_app_id' => $app->id])->save();
        app(ManageDatabases::class)->create($server->fresh(), 'blog', admin: true);
        app(ManageDatabases::class)->create($server->fresh(), 'extra');

        $this->get('/databases/blog-db')->assertInertia(fn (Assert $page) => $page
            ->where('permissions.whole_server', true)
            ->where('permissions.users.0.user', 'blog')
            ->where('permissions.users.0.scope', 'server')
            ->where('permissions.users.0.granted.grant_option', true)
            ->where('permissions.users.1.user', 'extra')
            ->where('permissions.users.1.scope', 'database')
            ->where('permissions.users.1.granted.grant_option', false));
    });

    it('does not mention the app on an ordinary or a dedicated server page, and needs no running server', function () {
        $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Mariadb, '11.4')->fresh();
        $server->forceFill(['status' => 'stopped'])->save();

        $this->get('/databases/main')->assertInertia(fn (Assert $page) => $page
            ->where('permissions.users', [])
            ->missing('server.dedicated_app'));
    });
});

describe('the page of one user', function () {
    beforeEach(function () {
        // A page that is new has no entry in a build made before it existed.
        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        $this->server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Mariadb, '11.4');
        $this->database = app(ManageDatabases::class)->create($this->server->fresh(), 'shop');
    });

    it('shows what the user may do, and lists the users on the server page as a short list', function () {
        $this->get("/databases/{$this->server->slug}/databases/{$this->database->id}/permissions")
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('databases/permissions')
            ->where('user_id', $this->database->id)
            ->where('server.slug', $this->server->slug)
            ->where('permissions.users.0.user', $this->database->username)
            ->where('permissions.users.0.granted.select', true));

        $this->get("/databases/{$this->server->slug}")->assertInertia(fn (Assert $page) => $page
            ->where('permissions.users.0.id', $this->database->id));
    });

    it('does not open a database of another server', function () {
        $other = app(DatabaseServers::class)->create('Other', DatabaseEngine::Mariadb, '11.4');

        $this->get("/databases/{$other->slug}/databases/{$this->database->id}/permissions")->assertNotFound();
    });

    it('needs the permission to see databases', function () {
        $this->actingAs(User::factory()->regular()->create());

        $this->get("/databases/{$this->server->slug}/databases/{$this->database->id}/permissions")->assertForbidden();
    });
});
