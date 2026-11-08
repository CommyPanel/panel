<?php

use App\Databases\AppLinks;
use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Deploy\Deployer;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\TemplateSourceFetcher;
use App\Deploy\Stacks\StackDetector;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\DatabaseEngine;
use App\Enums\DatabaseServerStatus;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\Enums\Stack;
use App\FastApps\FastApps;
use App\FastApps\QuickInstall;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\AppVolume;
use App\Models\AuditLog;
use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\FakeHealthChecker;
use Tests\Support\RecordingTrafficSwitcher;

beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/quick-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 0]);
    Sleep::fake();
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
});

afterEach(fn () => File::deleteDirectory($this->dataPath));

function wordpressForm(array $settings = []): array
{
    return [
        'name' => 'My Blog',
        'source' => 'template',
        'template' => 'wordpress',
        'settings' => ['site_title' => 'My Blog', 'admin_user' => 'maria', 'admin_email' => 'maria@example.com', 'admin_password' => 'a-long-password-1', ...$settings],
    ];
}

function dbServer(DatabaseEngine $engine = DatabaseEngine::Mariadb, string $version = '11.4', string $name = 'Main'): DatabaseServer
{
    return app(DatabaseServers::class)->create($name, $engine, $version)->fresh();
}

describe('the create page', function () {
    it('offers WordPress with the fields it needs, and nothing secret', function () {
        $this->get('/apps/create')->assertInertia(fn (Assert $page) => $page
            ->component('apps/create')
            ->where('fast_apps.0.id', 'wordpress')
            ->where('fast_apps.0.name', 'WordPress')
            ->where('fast_apps.0.engines', ['mariadb', 'mysql'])
            ->where('fast_apps.0.fields.0.key', 'site_title')
            ->where('fast_apps.0.fields.3.type', 'password'));
    });
});

describe('the first step', function () {
    it('makes the draft app with its volume, its variables and no repository', function () {
        $this->post('/apps', wordpressForm())->assertRedirect('/apps/my-blog/setup');

        $app = App::query()->firstOrFail();

        expect($app->source_type)->toBe(SourceType::Template)
            ->and($app->template)->toBe('wordpress')
            ->and($app->stack)->toBe(Stack::Wordpress)
            ->and($app->setup_step)->toBe(SetupStep::Database)
            ->and($app->container_port)->toBe(80)
            ->and($app->health_path)->toBe('/wp-login.php')
            ->and($app->git_url)->toBeNull()
            ->and($app->source_verified_at)->not->toBeNull()
            ->and($app->volumes()->pluck('path')->all())->toBe(['/var/www/html/wp-content']);

        // The password is only a variable, never in the settings kept for showing.
        expect($app->template_settings)->toBe(['site_title' => 'My Blog', 'admin_user' => 'maria', 'admin_email' => 'maria@example.com'])
            ->and($app->environment()['WP_ADMIN_PASSWORD'])->toBe('a-long-password-1')
            ->and($app->environment())->toHaveKeys(['WORDPRESS_AUTH_KEY', 'WORDPRESS_NONCE_SALT', 'WP_ADMIN_USER', 'WP_SITE_TITLE'])
            ->and(collect($app->environment())->keys()->filter(fn ($key) => str_starts_with($key, 'WORDPRESS_'))->count())->toBe(8);
    });

    it('makes the salts once, different for every app', function () {
        $this->post('/apps', wordpressForm());
        $this->post('/apps', [...wordpressForm(), 'name' => 'Second Blog']);

        [$a, $b] = App::query()->orderBy('id')->get()->all();

        expect($a->environment()['WORDPRESS_AUTH_KEY'])->toHaveLength(64)->not->toBe($b->environment()['WORDPRESS_AUTH_KEY']);
    });

    it('checks every field', function (array $settings, string $field) {
        $this->post('/apps', wordpressForm($settings))->assertSessionHasErrors("settings.{$field}");

        expect(App::query()->count())->toBe(0);
    })->with([
        'no title' => [['site_title' => ''], 'site_title'],
        'a title with a line break' => [['site_title' => "a\nb"], 'site_title'],
        'a short user name' => [['admin_user' => 'ab'], 'admin_user'],
        'a user name with spaces' => [['admin_user' => 'maria lopez'], 'admin_user'],
        'a bad email' => [['admin_email' => 'nope'], 'admin_email'],
        'a short password' => [['admin_password' => 'short'], 'admin_password'],
    ]);

    it('refuses an app that does not exist, and a template with no settings', function () {
        $this->post('/apps', [...wordpressForm(), 'template' => 'nothing'])->assertSessionHasErrors('template');
        $this->post('/apps', ['name' => 'X Blog', 'source' => 'template', 'template' => 'wordpress'])->assertSessionHasErrors('settings');
    });

    it('is audited', function () {
        $this->post('/apps', wordpressForm());

        expect(AuditLog::query()->where('action', 'app.setup_started')->firstOrFail()->metadata)->toMatchArray(['app' => 'my-blog', 'template' => 'wordpress']);
    });
});

describe('the database step', function () {
    beforeEach(function () {
        $this->post('/apps', wordpressForm());
        $this->wp = App::query()->firstOrFail();
    });

    it('lists only the servers WordPress works with', function () {
        dbServer(DatabaseEngine::Mariadb, '11.4', 'Maria');
        dbServer(DatabaseEngine::Postgres, '17', 'Pg');

        $this->get('/apps/my-blog/setup')->assertInertia(fn (Assert $page) => $page
            ->component('apps/setup')
            ->where('quick.template.name', 'WordPress')
            ->where('quick.template.admin_path', '/wp-admin/')
            ->has('quick.servers', 1)
            ->where('quick.servers.0.name', 'Maria')
            ->where('quick.engines.0.value', 'mariadb')
            ->where('quick.selected', null)
            ->where('quick.ready', false));
    });

    it('moves on to the domain once a running server is chosen', function () {
        $server = dbServer();

        $this->post('/apps/my-blog/setup/database', ['server' => $server->id])->assertRedirect('/apps/my-blog/setup');

        expect($this->wp->fresh()->setup_step)->toBe(SetupStep::Domain)
            ->and($this->wp->fresh()->template_database_server_id)->toBe($server->id);
    });

    it('does not accept a PostgreSQL server, or one that is still starting', function () {
        $pg = dbServer(DatabaseEngine::Postgres, '17', 'Pg');
        $this->post('/apps/my-blog/setup/database', ['server' => $pg->id])->assertSessionHasErrors('server');

        $starting = dbServer(name: 'Slow');
        $starting->forceFill(['status' => DatabaseServerStatus::Provisioning])->save();
        $this->post('/apps/my-blog/setup/database', ['server' => $starting->id])->assertSessionHasErrors('server');

        expect($this->wp->fresh()->setup_step)->toBe(SetupStep::Database);
    });

    it('only works at its step', function () {
        $server = dbServer();
        $this->wp->update(['setup_step' => SetupStep::Domain]);

        $this->post('/apps/my-blog/setup/database', ['server' => $server->id])->assertNotFound();
    });

    it('goes back from the domain step to the database step, and not further', function () {
        $this->wp->update(['setup_step' => SetupStep::Domain]);

        $this->post('/apps/my-blog/setup/back');
        expect($this->wp->fresh()->setup_step)->toBe(SetupStep::Database);

        $this->post('/apps/my-blog/setup/back')->assertNotFound();
    });

    it('creates a new server from inside the wizard and chooses it', function () {
        Queue::fake();

        $this->post('/apps/my-blog/setup/database-server', ['name' => 'Blog DB', 'engine' => 'mysql', 'version' => '8.4'])->assertSessionHasNoErrors();

        $server = DatabaseServer::query()->firstOrFail();
        expect($server->engine)->toBe(DatabaseEngine::Mysql)
            ->and($this->wp->fresh()->template_database_server_id)->toBe($server->id)
            ->and(AuditLog::query()->where('action', 'database_server.created')->exists())->toBeTrue();
    });

    it('does not create a PostgreSQL server for WordPress', function () {
        $this->post('/apps/my-blog/setup/database-server', ['name' => 'Blog DB', 'engine' => 'postgres', 'version' => '17'])->assertSessionHasErrors('engine');

        expect(DatabaseServer::query()->count())->toBe(0);
    });

    it('needs the permission to manage databases to create a server', function () {
        $user = User::factory()->regular()->create();
        $user->givePermissionTo(Permission::findOrCreate('apps.create'));
        $this->actingAs($user);

        $this->post('/apps/my-blog/setup/database-server', ['name' => 'Blog DB', 'engine' => 'mysql', 'version' => '8.4'])->assertForbidden();
    });
});

describe('finishing', function () {
    beforeEach(function () {
        $this->post('/apps', wordpressForm());
        $this->wp = App::query()->firstOrFail();
        $this->server = dbServer();
        $this->post('/apps/my-blog/setup/database', ['server' => $this->server->id]);
    });

    it('makes the database, links it with the names WordPress reads, and starts the first deployment', function () {
        Queue::fake();

        $this->post('/apps/my-blog/setup/finish')->assertRedirect('/apps/my-blog/deployments/1');

        $database = $this->server->databases()->firstOrFail();
        $link = $this->wp->databaseLinks()->firstOrFail();
        $env = app(AppLinks::class)->environment($this->wp->fresh());

        expect($database->name)->toBe('wordpress_my_blog')
            ->and($link->env_prefix)->toBe('WORDPRESS_DB')
            ->and($env)->toHaveKeys(['WORDPRESS_DB_HOST', 'WORDPRESS_DB_USER', 'WORDPRESS_DB_PASSWORD', 'WORDPRESS_DB_NAME'])
            ->and($env['WORDPRESS_DB_NAME'])->toBe('wordpress_my_blog')
            ->and($this->wp->fresh()->setup_step)->toBeNull()
            ->and($this->wp->fresh()->nginx_custom)->toContain('client_max_body_size 128m;');

        Queue::assertPushed(RunDeployment::class);
    });

    it('does not start when the server stopped running, and keeps nothing half made', function () {
        Queue::fake();
        $this->server->forceFill(['status' => DatabaseServerStatus::Stopped])->save();

        $this->post('/apps/my-blog/setup/finish')->assertSessionHasErrors('setup');

        expect($this->server->databases()->count())->toBe(0)
            ->and($this->wp->fresh()->setup_step)->toBe(SetupStep::Domain);
        Queue::assertNothingPushed();
    });

    it('picks another name when the database name is taken on the server', function () {
        Queue::fake();
        app(ManageDatabases::class)->create($this->server, 'wordpress_my_blog');

        $this->post('/apps/my-blog/setup/finish');

        expect($this->server->databases()->pluck('name')->sort()->values()->all())->toBe(['wordpress_my_blog', 'wordpress_my_blog_2']);
    });
});

describe('the build', function () {
    it('writes a build context from the official image, which the stack detector recognises', function () {
        $this->post('/apps', wordpressForm());
        $app = App::query()->firstOrFail();
        $dir = $this->dataPath.'/ctx';
        File::ensureDirectoryExists($dir);

        $fetcher = app(TemplateSourceFetcher::class);
        $sha = $fetcher->fetch($app, $dir);

        expect($sha)->toStartWith('template-wordpress-')
            ->and($fetcher->lastCommitMessage())->toBe('WordPress 6')
            ->and(File::get("{$dir}/Dockerfile"))->toContain('FROM wordpress:6-php8.3-apache')->toContain('ENTRYPOINT ["commypanel-entrypoint.sh"]')
            ->and(File::get("{$dir}/commypanel-config.php"))->toContain("define('WP_HOME'")->toContain('WP_AUTO_UPDATE_CORE')
            ->and(File::exists("{$dir}/commypanel.ini"))->toBeTrue();

        $plan = app(StackDetector::class)->plan($dir, 80);

        expect($plan->stack)->toBe(Stack::Wordpress)->and($plan->dockerfile)->toBeNull();
    });

    it('never puts a secret into the build context', function () {
        $this->post('/apps', wordpressForm());
        $app = App::query()->firstOrFail();
        $dir = $this->dataPath.'/ctx';
        File::ensureDirectoryExists($dir);
        app(TemplateSourceFetcher::class)->fetch($app, $dir);

        foreach (File::files($dir, true) as $file) {
            expect(File::get($file->getPathname()))->not->toContain('a-long-password-1')->not->toContain($app->environment()['WORDPRESS_AUTH_KEY']);
        }
    });

    it('has shell scripts that parse', function () {
        $node = trim((string) shell_exec('command -v bash'));
        $app = new App(['template' => 'wordpress', 'slug' => 'x']);

        foreach (['commypanel-entrypoint.sh', 'commypanel-install.sh'] as $script) {
            $path = $this->dataPath.'/'.$script;
            File::ensureDirectoryExists($this->dataPath);
            File::put($path, FastApps::find('wordpress')->files($app)[$script]);
            exec(($node !== '' ? $node : 'bash').' -n '.escapeshellarg($path).' 2>&1', $out, $code);
            expect($code)->toBe(0, implode("\n", $out));
        }
    })->skip(fn () => trim((string) shell_exec('command -v bash')) === '', 'bash is not installed');
});

describe('a deployment', function () {
    it('builds, goes live, and drops the first user password but keeps the other settings', function () {
        $this->post('/apps', wordpressForm());
        $app = App::query()->firstOrFail();
        $server = dbServer();
        app(QuickInstall::class)->chooseServer($app, $server);
        $app->forceFill(['setup_step' => SetupStep::Domain])->save();
        app(QuickInstall::class)->finish($app->fresh());
        $app->forceFill(['setup_step' => null, 'config_changed_at' => null])->save();

        app()->instance(HealthChecker::class, new FakeHealthChecker);
        app()->instance(TrafficSwitcher::class, new RecordingTrafficSwitcher);

        $deployment = app(Deployer::class)->deploy($app->fresh())->fresh();

        $app = $app->fresh();
        $spec = collect($this->host->actions())->where('action', 'runContainer')->last()['args']['spec'];

        expect($deployment->status->value)->toBe('active')
            ->and($deployment->commit_message)->toBe('WordPress 6')
            ->and($app->stack)->toBe(Stack::Wordpress)
            ->and($app->environment())->not->toHaveKey('WP_ADMIN_PASSWORD')
            ->and($app->environment())->toHaveKeys(['WP_ADMIN_USER', 'WP_SITE_TITLE', 'WORDPRESS_AUTH_KEY'])
            // Taking it away needs nothing applied.
            ->and($app->config_changed_at)->toBeNull()
            ->and($spec->containerPort)->toBe(80)
            ->and($spec->env)->toHaveKeys(['WP_ADMIN_PASSWORD', 'WORDPRESS_DB_HOST', 'WORDPRESS_DB_NAME', 'WORDPRESS_AUTH_KEY'])
            ->and(collect($spec->volumes)->pluck('target')->all())->toBe(['/var/www/html/wp-content']);

        // The volume is the app's own folder, writable by the container.
        expect(AppVolume::query()->where('app_id', $app->id)->count())->toBe(1);
    });
});

describe('the app page', function () {
    it('says what it is made from and where to sign in', function () {
        $this->post('/apps', wordpressForm());
        $app = App::query()->firstOrFail();
        $app->forceFill(['setup_step' => null])->save();

        $this->get('/apps/my-blog')->assertInertia(fn (Assert $page) => $page
            ->where('app.source_type', 'template')
            ->where('app.template.name', 'WordPress')
            ->where('app.template.settings.admin_user', 'maria')
            ->missing('app.template.settings.admin_password'));
    });

    it('can still change the runtime settings (no repository fields are asked for)', function () {
        $this->post('/apps', wordpressForm());
        $app = App::query()->firstOrFail();
        $app->forceFill(['setup_step' => null])->save();

        $this->put('/apps/my-blog', ['name' => 'My Blog', 'container_port' => 80, 'health_path' => '/wp-login.php', 'memory_limit_mb' => 768])->assertSessionHasNoErrors();

        expect($app->fresh()->memory_limit_mb)->toBe(768);
    });
});
