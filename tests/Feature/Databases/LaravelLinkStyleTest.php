<?php

use App\Databases\AppLinks;
use App\Databases\DatabaseException;
use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Deploy\DestroyApp;
use App\Enums\DatabaseEngine;
use App\Enums\DatabaseServerStatus;
use App\Enums\SetupStep;
use App\Models\App;
use App\Models\AppDatabaseLink;
use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Laravel-style links next to the prefix-style ones, the links tab, the Databases pages and what deleting an
 * app does to a server that was made for it.
 */
beforeEach(function () {
    config(['commypanel.data_path' => storage_path('framework/testing/laravel-link-'.bin2hex(random_bytes(4)))]);
    Sleep::fake();
    $this->actingAs(User::factory()->create());
    $this->blog = App::factory()->create(['slug' => 'blog', 'name' => 'Blog']);
    $this->server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17')->fresh();
    $this->database = app(ManageDatabases::class)->create($this->server, 'shop');
});

afterEach(fn () => File::deleteDirectory(config('commypanel.data_path')));

describe('the two styles', function () {
    it('keeps a prefix link exactly as it was', function () {
        app(AppLinks::class)->linkDatabase($this->blog, $this->database, 'DATABASE');

        $env = app(AppLinks::class)->environment($this->blog->fresh());

        expect($this->blog->databaseLinks()->first()->env_style)->toBe('prefix')
            ->and(array_keys($env))->toBe(['DATABASE_URL', 'DATABASE_HOST', 'DATABASE_PORT', 'DATABASE_NAME', 'DATABASE_USER', 'DATABASE_PASSWORD'])
            ->and($env['DATABASE_URL'])->toStartWith('postgresql://shop:');
    });

    it('gives a Laravel link the names config/database.php reads, and a second one is refused by name', function () {
        $links = app(AppLinks::class);
        $link = $links->linkDatabase($this->blog, $this->database, 'ignored', AppDatabaseLink::STYLE_LARAVEL);

        expect($link->env_prefix)->toBe('DB')->and($link->isLaravelStyle())->toBeTrue()
            ->and(array_keys($links->environment($this->blog->fresh())))->toBe(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL']);

        $other = app(ManageDatabases::class)->create($this->server, 'second');

        expect(fn () => $links->linkDatabase($this->blog, $other, 'ignored', AppDatabaseLink::STYLE_LARAVEL))->toThrow(DatabaseException::class);
    });

    it('lets both styles live in one app and follows a password change', function () {
        $links = app(AppLinks::class);
        $links->linkDatabase($this->blog, $this->database, 'LEGACY');
        $second = app(ManageDatabases::class)->create($this->server, 'second');
        $links->linkDatabase($this->blog, $second, 'x', AppDatabaseLink::STYLE_LARAVEL);

        $env = $links->environment($this->blog->fresh());

        expect($env)->toHaveKeys(['LEGACY_URL', 'DB_HOST', 'DB_DATABASE'])->and($env['DB_DATABASE'])->toBe('second');

        $before = $env['DB_PASSWORD'];
        app(ManageDatabases::class)->rotatePassword($second);
        $after = $links->environment($this->blog->fresh())['DB_PASSWORD'];

        expect($after)->toBe($second->fresh()->password)->and($after)->not->toBe($before);
    });

    it('marks the app as having changes to apply', function () {
        $this->blog->forceFill(['config_changed_at' => null])->save();

        app(AppLinks::class)->linkDatabase($this->blog, $this->database, 'x', AppDatabaseLink::STYLE_LARAVEL);

        expect($this->blog->fresh()->config_changed_at)->not->toBeNull();
    });
});

describe('the links tab', function () {
    it('shows each link\'s style, and a link made there can be Laravel style', function () {
        app(AppLinks::class)->linkDatabase($this->blog, $this->database, 'DATABASE');
        $second = app(ManageDatabases::class)->create($this->server, 'second');

        $this->post('/apps/blog/links/database', ['database_id' => $second->id, 'env_prefix' => 'ignored', 'env_style' => 'laravel'])->assertSessionHasNoErrors();

        $links = $this->getJson('/apps/blog/links')->assertOk()->json('databases');

        expect(collect($links)->pluck('style', 'database')->all())->toBe(['shop' => 'prefix', 'second' => 'laravel'])
            ->and(collect($links)->firstWhere('database', 'second')['prefix'])->toBe('DB');
    });

    it('refuses an unknown style', function () {
        $this->post('/apps/blog/links/database', ['database_id' => $this->database->id, 'env_prefix' => 'DATABASE', 'env_style' => 'rails'])->assertSessionHasErrors('env_style');
    });
});

describe('the Databases pages', function () {
    it('do not announce the app of a dedicated server in the list or on the server page', function () {
        $dedicated = app(DatabaseServers::class)->create('Blog db', DatabaseEngine::Mysql, '8.4')->fresh();
        $dedicated->forceFill(['dedicated_app_id' => $this->blog->id])->save();

        $this->get('/databases')->assertInertia(fn (Assert $page) => $page->missing('servers.0.dedicated_app'));
        $this->get('/databases/blog-db')->assertInertia(fn (Assert $page) => $page->missing('server.dedicated_app'));
    });

    it('still needs the typed name to delete a dedicated server', function () {
        $dedicated = app(DatabaseServers::class)->create('Blog db', DatabaseEngine::Mysql, '8.4')->fresh();
        $dedicated->forceFill(['dedicated_app_id' => $this->blog->id])->save();

        $this->withSession(['auth.password_confirmed_at' => time()])->delete('/databases/blog-db', ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        expect(DatabaseServer::query()->where('slug', 'blog-db')->exists())->toBeTrue();

        $this->withSession(['auth.password_confirmed_at' => time()])->delete('/databases/blog-db', ['confirm' => 'blog-db'])->assertSessionHasNoErrors();
        expect(DatabaseServer::query()->where('slug', 'blog-db')->exists())->toBeFalse();
    });
});

describe('deleting the app', function () {
    beforeEach(function () {
        $this->dedicated = app(DatabaseServers::class)->create('Blog db', DatabaseEngine::Postgres, '17')->fresh();
        $this->dedicated->forceFill(['dedicated_app_id' => $this->blog->id])->save();
    });

    it('keeps its dedicated server, as an ordinary one, with its databases', function () {
        $database = app(ManageDatabases::class)->create($this->dedicated, 'blog', admin: true);

        app(DestroyApp::class)($this->blog);

        $server = $this->dedicated->fresh();

        expect(App::query()->where('slug', 'blog')->exists())->toBeFalse()
            ->and($server)->not->toBeNull()
            ->and($server->dedicated_app_id)->toBeNull()
            ->and($server->status)->toBe(DatabaseServerStatus::Running)
            ->and($server->databases()->pluck('name')->all())->toBe(['blog'])
            ->and($database->fresh())->not->toBeNull();
    });

    it('tells the person in the delete dialog and in the answer', function () {
        $this->get('/apps/blog')->assertInertia(fn (Assert $page) => $page->where('app.dedicated_servers', ['Blog db']));

        $this->delete('/apps/blog', ['confirm' => 'blog'])
            ->assertRedirect('/apps')
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Blog db') && str_contains($status, 'kept'));
    });

    it('says nothing extra for an app without one', function () {
        $plain = App::factory()->create(['slug' => 'plain']);

        $this->delete('/apps/plain', ['confirm' => 'plain'])->assertSessionHas('status', 'App plain deleted.');
        $this->get('/apps/blog')->assertInertia(fn (Assert $page) => $page->where('app.dedicated_servers', ['Blog db']));
        expect($plain->fresh())->toBeNull();
    });

    it('does not remove the server when the draft is cancelled', function () {
        $draft = App::factory()->create(['slug' => 'draft', 'setup_step' => SetupStep::Database, 'source_verified_at' => now()]);
        $this->dedicated->forceFill(['dedicated_app_id' => $draft->id])->save();

        $this->delete('/apps/draft/setup')->assertRedirect();

        expect($this->dedicated->fresh()->dedicated_app_id)->toBeNull();
    });
});
