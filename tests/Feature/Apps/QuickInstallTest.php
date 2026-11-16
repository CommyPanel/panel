<?php

use App\Audit\Severity;
use App\Databases\AppLinks;
use App\Deploy\Deployer;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\TemplateSourceFetcher;
use App\Deploy\Stacks\StackDetector;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\Enums\Stack;
use App\FastApps\FastApps;
use App\FastApps\QuickInstall;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\File;
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

function quickForm(string $template, string $name, array $settings = []): array
{
    return ['name' => $name, 'source' => 'template', 'template' => $template, 'settings' => $settings];
}

/** An Elasticsearch app that is finished, as Kibana needs one. */
function finishedElasticsearch(string $name = 'Search'): App
{
    test()->post('/apps', quickForm('elasticsearch', $name))->assertRedirect();
    $app = App::query()->where('template', 'elasticsearch')->latest('id')->firstOrFail();
    $app->forceFill(['setup_step' => null])->save();

    return $app;
}

describe('the create page', function () {
    it('lists the apps, warns about Elasticsearch and offers Kibana the Elasticsearch apps', function () {
        finishedElasticsearch('Logs');

        $this->get('/apps/create')->assertInertia(fn (Assert $page) => $page
            ->component('apps/create')
            ->where('fast_apps', fn ($apps) => collect($apps)->pluck('id')->all() === ['meilisearch', 'typesense', 'grafana', 'elasticsearch', 'kibana', 'uptime-kuma', 'mailpit', 'gotenberg', 'redis', 'n8n', 'garage'])
            ->where('fast_apps.3.warnings.1', fn ($text) => str_contains($text, 'vm.max_map_count=262144'))
            ->where('fast_apps.4.fields.0.key', 'elasticsearch')
            ->where('fast_apps.4.fields.0.options', [['value' => 'logs', 'label' => 'Logs']]));
    });

    it('never sends a secret', function () {
        $html = json_encode(FastApps::catalog());

        expect($html)->not->toContain('PASSWORD')->not->toContain('MASTER_KEY');
    });
});

describe('the first step', function () {
    it('makes the app with its volume, port and generated secret, at the domain step', function (string $template, string $variable, int $port, string $volume) {
        $this->post('/apps', quickForm($template, 'Quick One'))->assertRedirect('/apps/quick-one/setup');

        $app = App::query()->firstOrFail();

        expect($app->source_type)->toBe(SourceType::Template)
            ->and($app->template)->toBe($template)
            ->and($app->stack)->toBe(Stack::Template)
            ->and($app->setup_step)->toBe(SetupStep::Domain)
            ->and($app->container_port)->toBe($port)
            ->and($app->git_url)->toBeNull()
            ->and($app->source_verified_at)->not->toBeNull()
            ->and($app->volumes()->pluck('path')->all())->toBe([$volume])
            ->and($app->environment()[$variable])->toBeString()->not->toBeEmpty();
    })->with([
        'Meilisearch' => ['meilisearch', 'MEILI_MASTER_KEY', 7700, '/meili_data'],
        'Typesense' => ['typesense', 'TYPESENSE_API_KEY', 8108, '/data'],
        'Grafana' => ['grafana', 'GF_SECURITY_ADMIN_PASSWORD', 3000, '/var/lib/grafana'],
        'Elasticsearch' => ['elasticsearch', 'ELASTIC_PASSWORD', 9200, '/usr/share/elasticsearch/data'],
    ]);

    it('gives Elasticsearch memory and a longer start', function () {
        $this->post('/apps', quickForm('elasticsearch', 'Search'));
        $app = App::query()->firstOrFail();

        expect($app->memory_limit_mb)->toBe(2048)
            ->and($app->health_timeout)->toBe(240)
            ->and($app->environment()['ES_JAVA_OPTS'])->toBe('-Xms1g -Xmx1g');
    });

    it('makes different secrets for every app', function () {
        $this->post('/apps', quickForm('meilisearch', 'First Search'));
        $this->post('/apps', quickForm('meilisearch', 'Second Search'));

        [$a, $b] = App::query()->orderBy('id')->get()->all();

        expect($a->environment()['MEILI_MASTER_KEY'])->toHaveLength(40)->not->toBe($b->environment()['MEILI_MASTER_KEY']);
    });

    it('refuses an unknown recipe', function () {
        $this->post('/apps', quickForm('wordpress', 'Blog'))->assertSessionHasErrors('template');

        expect(App::query()->count())->toBe(0);
    });

    it('is audited', function () {
        $this->post('/apps', quickForm('grafana', 'Charts'));

        expect(AuditLog::query()->where('action', 'app.setup_started')->firstOrFail()->metadata)->toMatchArray(['app' => 'charts', 'template' => 'grafana']);
    });

    it('has no earlier step to go back to', function () {
        $this->post('/apps', quickForm('grafana', 'Charts'));

        $this->post('/apps/charts/setup/back')->assertNotFound();
    });
});

describe('Kibana', function () {
    it('needs one of the Elasticsearch apps', function () {
        $this->post('/apps', quickForm('kibana', 'Board'))->assertSessionHasErrors('settings.elasticsearch');
        $this->post('/apps', quickForm('kibana', 'Board', ['elasticsearch' => 'nothing']))->assertSessionHasErrors('settings.elasticsearch');

        // An app of another recipe does not count.
        $this->post('/apps', quickForm('grafana', 'Charts'));
        App::query()->update(['setup_step' => null]);
        $this->post('/apps', quickForm('kibana', 'Board', ['elasticsearch' => 'charts']))->assertSessionHasErrors('settings.elasticsearch');

        expect(App::query()->where('template', 'kibana')->count())->toBe(0);
    });

    it('is linked to the chosen app and told where it is and how to sign in', function () {
        $search = finishedElasticsearch();

        $this->post('/apps', quickForm('kibana', 'Board', ['elasticsearch' => 'search']))->assertRedirect('/apps/board/setup');

        $kibana = App::query()->where('template', 'kibana')->firstOrFail();
        $env = $kibana->environment();

        expect($kibana->template_settings)->toBe(['elasticsearch' => 'search'])
            ->and($kibana->links()->firstOrFail()->target_app_id)->toBe($search->id)
            ->and($kibana->links()->firstOrFail()->env_prefix)->toBe('ES')
            ->and($env['ELASTICSEARCH_HOSTS'])->toBe('http://search:9200')
            ->and($env['ELASTICSEARCH_USERNAME'])->toBe('kibana_system')
            ->and($env['COMMYPANEL_ELASTIC_PASSWORD'])->toBe($search->environment()['ELASTIC_PASSWORD'])
            ->and($env['ELASTICSEARCH_PASSWORD'])->not->toBe($env['COMMYPANEL_ELASTIC_PASSWORD'])
            ->and($env['XPACK_SECURITY_ENCRYPTIONKEY'])->toHaveLength(40)
            ->and($kibana->container_port)->toBe(5601)
            ->and($kibana->volumes()->count())->toBe(0);
    });

    it('drops the Elasticsearch superuser password once it is live', function () {
        finishedElasticsearch();
        $this->post('/apps', quickForm('kibana', 'Board', ['elasticsearch' => 'search']));
        $kibana = App::query()->where('template', 'kibana')->firstOrFail();
        $kibana->forceFill(['setup_step' => null, 'config_changed_at' => null])->save();

        app()->instance(HealthChecker::class, new FakeHealthChecker);
        app()->instance(TrafficSwitcher::class, new RecordingTrafficSwitcher);

        app(Deployer::class)->deploy($kibana->fresh());

        $kibana = $kibana->fresh();
        $spec = collect($this->host->actions())->where('action', 'runContainer')->last()['args']['spec'];

        expect($kibana->environment())->not->toHaveKey('COMMYPANEL_ELASTIC_PASSWORD')
            ->and($kibana->environment())->toHaveKeys(['ELASTICSEARCH_PASSWORD', 'ELASTICSEARCH_HOSTS'])
            ->and($kibana->config_changed_at)->toBeNull()
            // The first start still had it, and the link network.
            ->and($spec->env)->toHaveKey('COMMYPANEL_ELASTIC_PASSWORD')
            ->and(collect($spec->networks)->pluck('name')->all())->toHaveCount(1);
    });
});

describe('the build', function () {
    it('writes a build context from the pinned official image, which the stack detector recognises', function (string $template, string $image) {
        $this->post('/apps', quickForm($template, 'Quick One', $template === 'kibana' ? ['elasticsearch' => 'search'] : []));
        $app = $template === 'kibana' ? new App(['template' => 'kibana', 'slug' => 'board']) : App::query()->firstOrFail();
        $dir = $this->dataPath.'/ctx';
        File::ensureDirectoryExists($dir);

        $fetcher = app(TemplateSourceFetcher::class);
        $sha = $fetcher->fetch($app, $dir);

        expect($sha)->toStartWith("template-{$template}-")
            ->and($fetcher->lastCommitMessage())->toBe($image)
            ->and(File::get("{$dir}/Dockerfile"))->toStartWith("FROM {$image}");

        $plan = app(StackDetector::class)->plan($dir, 80);

        expect($plan->stack)->toBe(Stack::Template)->and($plan->dockerfile)->toBeNull();
    })->with([
        'Meilisearch' => ['meilisearch', 'getmeili/meilisearch:v1.15'],
        'Typesense' => ['typesense', 'typesense/typesense:28.0'],
        'Grafana' => ['grafana', 'grafana/grafana:12.2.0'],
        'Elasticsearch' => ['elasticsearch', 'docker.elastic.co/elasticsearch/elasticsearch:8.19.0'],
        'Kibana' => ['kibana', 'docker.elastic.co/kibana/kibana:8.19.0'],
        'Uptime Kuma' => ['uptime-kuma', 'louislam/uptime-kuma:2.5.11'],
        'Mailpit' => ['mailpit', 'axllent/mailpit:v1.33'],
        'Gotenberg' => ['gotenberg', 'gotenberg/gotenberg:9.5'],
        'Redis' => ['redis', 'redis:8.10'],
        'n8n' => ['n8n', 'docker.n8n.io/n8nio/n8n:3.2.2'],
        'Garage' => ['garage', 'dxflrs/garage:v2.5.0'],
    ]);

    it('secures Elasticsearch in its own settings file', function () {
        $files = FastApps::find('elasticsearch')->files(new App(['slug' => 'search']));

        expect($files['elasticsearch.yml'])->toContain('xpack.security.enabled: true')
            ->toContain('discovery.type: single-node')
            ->toContain('node.store.allow_mmap: false')
            ->and($files['Dockerfile'])->toContain('COPY --chown=1000:0 elasticsearch.yml');
    });

    it('never puts a generated secret into any build context', function () {
        $secrets = [];

        foreach (['meilisearch', 'typesense', 'grafana', 'elasticsearch', 'mailpit', 'gotenberg', 'redis', 'n8n', 'garage'] as $template) {
            $this->post('/apps', quickForm($template, ucfirst($template).' App'));
        }
        finishedElasticsearch('Search');
        $this->post('/apps', quickForm('kibana', 'Board', ['elasticsearch' => 'search']));

        foreach (App::query()->get() as $app) {
            foreach ($app->environment() as $key => $value) {
                if (strlen($value) >= 20) {
                    $secrets[] = $value;
                }
            }

            foreach (FastApps::find($app->template)->files($app) as $contents) {
                foreach ($secrets as $secret) {
                    expect($contents)->not->toContain($secret);
                }
            }
        }

        expect($secrets)->not->toBeEmpty();
    });

    it('has a Kibana start script that parses', function () {
        $node = trim((string) shell_exec('command -v node'));
        $path = $this->dataPath.'/start.js';
        File::ensureDirectoryExists($this->dataPath);
        File::put($path, FastApps::find('kibana')->files(new App(['slug' => 'board']))['commypanel-start.js']);

        exec($node.' --check '.escapeshellarg($path).' 2>&1', $out, $code);

        expect($code)->toBe(0, implode("\n", $out));
    })->skip(fn () => trim((string) shell_exec('command -v node')) === '', 'node is not installed');
});

describe('a deployment', function () {
    it('builds, runs the container with the recipe\'s volume and variables and goes live', function () {
        $this->post('/apps', quickForm('typesense', 'Search'));
        $app = App::query()->firstOrFail();
        $app->forceFill(['setup_step' => null])->save();

        app()->instance(HealthChecker::class, new FakeHealthChecker);
        app()->instance(TrafficSwitcher::class, new RecordingTrafficSwitcher);

        $deployment = app(Deployer::class)->deploy($app->fresh())->fresh();
        $spec = collect($this->host->actions())->where('action', 'runContainer')->last()['args']['spec'];

        expect($deployment->status->value)->toBe('active')
            ->and($deployment->commit_message)->toBe('typesense/typesense:28.0')
            ->and($app->fresh()->stack)->toBe(Stack::Template)
            ->and($spec->containerPort)->toBe(8108)
            ->and($spec->env)->toHaveKey('TYPESENSE_API_KEY')
            ->and(collect($spec->volumes)->pluck('target')->all())->toBe(['/data']);
    });
});

describe('the passwords', function () {
    beforeEach(function () {
        $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->post('/apps', quickForm('grafana', 'Charts'));
        App::query()->firstOrFail()->forceFill(['setup_step' => null])->save();
    });

    it('asks for the password first', function () {
        $this->getJson('/apps/charts/credentials')->assertStatus(423);

        expect(AuditLog::query()->where('action', 'app.credentials_viewed')->count())->toBe(0);
    });

    it('shows the current values, with the secret ones marked, and audits it', function () {
        AppEnvVar::query()->where('key', 'GF_SECURITY_ADMIN_PASSWORD')->firstOrFail()->update(['value' => 'chosen-by-me']);

        ($this->confirmed)()->getJson('/apps/charts/credentials')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['rows' => [
                ['label' => 'User', 'value' => 'admin', 'secret' => false],
                ['label' => 'Password', 'value' => 'chosen-by-me', 'secret' => true],
            ]]);

        $log = AuditLog::query()->where('action', 'app.credentials_viewed')->sole();

        expect($log->metadata)->toMatchArray(['app' => 'charts', 'template' => 'grafana'])
            ->and(json_encode($log->metadata))->not->toContain('chosen-by-me')
            ->and(Severity::of('app.credentials_viewed'))->toBe('warning');
    });

    it('shows Kibana the password of its Elasticsearch app', function () {
        $search = finishedElasticsearch();
        $this->post('/apps', quickForm('kibana', 'Board', ['elasticsearch' => 'search']));
        App::query()->where('template', 'kibana')->firstOrFail()->forceFill(['setup_step' => null])->save();

        ($this->confirmed)()->getJson('/apps/board/credentials')->assertJson(['rows' => [
            ['label' => 'User', 'value' => 'elastic'],
            ['label' => 'Password', 'value' => $search->environment()['ELASTIC_PASSWORD']],
        ]]);
    });

    it('needs its own permission, and is for quick-install apps only', function () {
        $user = User::factory()->regular()->create();
        $user->givePermissionTo(Permission::findOrCreate('apps.view'));
        $this->actingAs($user);

        ($this->confirmed)()->getJson('/apps/charts/credentials')->assertForbidden();

        $this->actingAs(User::factory()->create());
        App::factory()->create(['slug' => 'plain']);

        ($this->confirmed)()->getJson('/apps/plain/credentials')->assertNotFound();
    });
});

describe('the second batch', function () {
    it('sets each one up with its port, volume, health check and generated secrets', function (string $template, int $port, array $volumes, array $variables, ?int $memory, ?int $timeout) {
        $this->post('/apps', quickForm($template, 'Quick One'))->assertRedirect('/apps/quick-one/setup');

        $app = App::query()->firstOrFail();

        expect($app->container_port)->toBe($port)
            ->and($app->volumes()->pluck('path')->all())->toBe($volumes)
            ->and(collect($app->environment())->keys()->sort()->values()->all())->toBe(collect($variables)->sort()->values()->all())
            ->and($app->memory_limit_mb)->toBe($memory)
            ->and($app->health_timeout)->toBe($timeout);

        foreach ($app->environment() as $key => $value) {
            expect($value)->not->toBe('', $key);
        }
    })->with([
        'Uptime Kuma' => ['uptime-kuma', 3001, ['/app/data'], [], null, 120],
        'Mailpit' => ['mailpit', 8025, ['/data'], ['MP_UI_AUTH'], null, null],
        'Gotenberg' => ['gotenberg', 3000, [], ['API_ENABLE_BASIC_AUTH', 'GOTENBERG_API_BASIC_AUTH_USERNAME', 'GOTENBERG_API_BASIC_AUTH_PASSWORD'], 1024, 90],
        'Redis' => ['redis', 6379, ['/data'], ['REDIS_PASSWORD'], null, 120],
        'n8n' => ['n8n', 5678, ['/home/node/.n8n'], ['N8N_ENCRYPTION_KEY'], 2048, 180],
        'Garage' => ['garage', 3900, ['/data'], ['GARAGE_RPC_SECRET', 'GARAGE_ADMIN_TOKEN', 'COMMYPANEL_ACCESS_KEY_ID', 'COMMYPANEL_SECRET_ACCESS_KEY', 'COMMYPANEL_BUCKET'], null, 120],
    ]);

    describe('memory chosen on the create page', function () {
        it('is kept for the new app, and the recipe default is used when none is given', function () {
            $this->post('/apps', [...quickForm('meilisearch', 'Search'), 'memory_limit_mb' => 768])->assertRedirect('/apps/search/setup');
            $this->post('/apps', quickForm('elasticsearch', 'Logs'))->assertRedirect('/apps/logs/setup');
            $this->post('/apps', [...quickForm('elasticsearch', 'Logs Big'), 'memory_limit_mb' => 4096])->assertRedirect('/apps/logs-big/setup');
            $this->post('/apps', [...quickForm('elasticsearch', 'Logs Empty'), 'memory_limit_mb' => ''])->assertRedirect('/apps/logs-empty/setup');

            expect(App::query()->where('slug', 'search')->value('memory_limit_mb'))->toBe(768)
                ->and(App::query()->where('slug', 'logs')->value('memory_limit_mb'))->toBe(2048)
                ->and(App::query()->where('slug', 'logs-big')->value('memory_limit_mb'))->toBe(4096)
                ->and(App::query()->where('slug', 'logs-empty')->value('memory_limit_mb'))->toBe(2048);
        });

        it('refuses less than an app can run in, and anything outside the usual range', function (string $template, int $memory, bool $accepted) {
            $response = $this->post('/apps', [...quickForm($template, 'Some App'), 'memory_limit_mb' => $memory]);

            if ($accepted) {
                $response->assertSessionHasNoErrors();
            } else {
                $response->assertSessionHasErrors('memory_limit_mb');
                expect(App::query()->where('slug', 'some-app')->exists())->toBeFalse();
            }
        })->with([
            'Elasticsearch below its floor' => ['elasticsearch', 1024, false],
            'Elasticsearch at its floor' => ['elasticsearch', 1536, true],
            'n8n below its floor' => ['n8n', 1024, false],
            'Kibana below its floor' => ['kibana', 512, false],
            'Meilisearch has no floor' => ['meilisearch', 128, true],
            'nothing under 16 MB' => ['meilisearch', 8, false],
            'nothing over 64 GB' => ['meilisearch', 70000, false],
        ]);

        it('is offered to the page with each recipe\'s default and floor', function () {
            $catalog = collect(FastApps::catalog())->keyBy('id');

            expect($catalog['elasticsearch']['memory_mb'])->toBe(2048)->and($catalog['elasticsearch']['min_memory_mb'])->toBe(1536)
                ->and($catalog['meilisearch']['memory_mb'])->toBeNull()->and($catalog['meilisearch']['min_memory_mb'])->toBeNull()
                ->and($catalog['n8n']['min_memory_mb'])->toBe(2048);
        });

        it('is ignored for an app that is not ready-made', function () {
            $this->post('/apps', ['name' => 'My Code', 'source' => 'git', 'git_url' => 'https://github.com/acme/shop.git', 'git_branch' => 'main', 'memory_limit_mb' => 4096])->assertRedirect();

            expect(App::query()->where('slug', 'my-code')->value('memory_limit_mb'))->toBeNull();
        });
    });

    it('stops the old version first for the apps that cannot run twice on the same data', function (string $id, string $strategy) {
        $recipe = FastApps::find($id);
        $app = app(QuickInstall::class)->create($recipe, 'My '.$recipe->name(), $id === 'kibana' ? ['elasticsearch' => App::factory()->create(['slug' => 'es', 'template' => 'elasticsearch', 'setup_step' => null])->slug] : []);

        expect($app->refresh()->deploy_strategy->value)->toBe($strategy);
    })->with([
        'Redis' => ['redis', 'stop_first'],
        'Garage' => ['garage', 'stop_first'],
        'Elasticsearch' => ['elasticsearch', 'stop_first'],
        'Meilisearch' => ['meilisearch', 'rolling'],
        'Grafana' => ['grafana', 'rolling'],
        'Kibana' => ['kibana', 'rolling'],
        'n8n' => ['n8n', 'rolling'],
    ]);

    it('warns about what to know first', function () {
        $warnings = collect(FastApps::catalog())->mapWithKeys(fn ($app) => [$app['id'] => $app['warnings']]);

        expect($warnings['uptime-kuma'])->toBe([])
            ->and($warnings['n8n'][0])->toContain('2 GB')
            ->and($warnings['n8n'][1])->toContain('HTTPS')
            ->and($warnings['gotenberg'][0])->toContain('1 GB');
    });

    it('explains what happens by itself, what is left to do and how to upgrade', function () {
        $guides = collect(FastApps::catalog())->mapWithKeys(fn ($app) => [$app['id'] => $app['guide']]);

        foreach ($guides as $guide) {
            expect($guide['automatic'])->not->toBeEmpty()->and($guide['after'])->not->toBeEmpty()->and($guide['upgrade'])->not->toBeEmpty();
        }

        expect($guides['uptime-kuma']['after'][0])->toContain('first person')
            ->and($guides['n8n']['after'][0])->toContain('first person')
            ->and($guides['redis']['after'][0])->toContain('no web interface')
            ->and($guides['redis']['upgrade'][1])->toContain('stops the old version first')
            ->and($guides['garage']['upgrade'][1])->toContain('stops the old version first')
            ->and($guides['elasticsearch']['upgrade'][1])->toContain('stops the old version first')
            ->and($guides['meilisearch']['upgrade'])->not->toContain('stops the old version first')
            ->and($guides['gotenberg']['upgrade'][0])->toContain('nothing to back up')
            ->and($guides['gotenberg']['automatic'])->not->toContain('Keeps its data in a persistent volume that survives deployments and rollbacks.')
            ->and($guides['uptime-kuma']['automatic'])->toContain('Chooses SQLite, so there is no database to set up.');
    });

    it('gives Garage a key in its own format, and Mailpit a login for the inbox', function () {
        $this->post('/apps', quickForm('garage', 'Files'));
        $this->post('/apps', quickForm('mailpit', 'Inbox'));
        $garage = App::query()->where('template', 'garage')->firstOrFail()->environment();
        $mail = App::query()->where('template', 'mailpit')->firstOrFail()->environment();

        expect($garage['COMMYPANEL_ACCESS_KEY_ID'])->toMatch('/^GK[0-9a-f]{24}$/')
            ->and($garage['COMMYPANEL_SECRET_ACCESS_KEY'])->toMatch('/^[0-9a-f]{64}$/')
            ->and($garage['GARAGE_RPC_SECRET'])->toMatch('/^[0-9a-f]{64}$/')
            ->and($mail['MP_UI_AUTH'])->toMatch('/^admin:[A-Za-z0-9]{24}$/');
    });

    describe('Redis', function () {
        it('has no web interface: it takes no domain, and says so on its page', function () {
            $this->post('/apps', quickForm('redis', 'Cache'));
            $app = App::query()->firstOrFail();
            $app->forceFill(['setup_step' => null])->save();

            $this->post('/apps/cache/domains', ['name' => 'cache.example.com', 'redirect' => 'none'])->assertSessionHasErrors('name');
            $this->get('/apps/cache')->assertInertia(fn (Assert $page) => $page->where('app.template.private', true));

            expect($app->domains()->count())->toBe(0);
        });

        it('is checked with a PING, while the others are checked over HTTP', function () {
            $this->post('/apps', quickForm('redis', 'Cache'));
            $this->post('/apps', quickForm('grafana', 'Charts'));
            $redis = App::query()->where('template', 'redis')->firstOrFail();
            $grafana = App::query()->where('template', 'grafana')->firstOrFail();

            expect($redis->pingHealth())->toBeTrue()->and($grafana->pingHealth())->toBeFalse();

            $redis->forceFill(['setup_step' => null, 'config_changed_at' => null])->save();
            $health = new FakeHealthChecker;
            app()->instance(HealthChecker::class, $health);
            app()->instance(TrafficSwitcher::class, new RecordingTrafficSwitcher);

            $deployment = app(Deployer::class)->deploy($redis->fresh())->fresh();

            expect($deployment->status->value)->toBe('active')
                ->and($health->pings)->toHaveCount(1)
                ->and($health->pings[0]['timeout'])->toBe(120)
                ->and($health->calls)->toBe([]);
        });

        it('keeps a second Redis off the same data, and keeps the password off the command line', function () {
            $script = FastApps::find('redis')->files(new App(['slug' => 'cache']))['commypanel-start.sh'];

            expect($script)->toContain('flock -n 9')
                ->toContain('Stop the app, then deploy again')
                ->toContain('echo "requirepass $REDIS_PASSWORD"')
                ->toContain('exec setpriv --reuid=redis --regid=redis --clear-groups redis-server "$conf"');
        });
    });

    describe('what a linked app is told', function () {
        function linkedTo(string $template, string $name, string $prefix = 'SVC'): array
        {
            test()->post('/apps', quickForm($template, $name));
            $target = App::query()->where('template', $template)->latest('id')->firstOrFail();
            $target->forceFill(['setup_step' => null])->save();
            $consumer = App::factory()->create(['slug' => 'consumer-'.$template, 'setup_step' => null]);

            app(AppLinks::class)->linkApp($consumer, $target, $prefix);

            return [$target->fresh(), app(AppLinks::class)->environment($consumer)];
        }

        it('gets a Redis URL with the password instead of the web-style one', function () {
            [$redis, $env] = linkedTo('redis', 'Cache', 'CACHE');
            $password = $redis->environment()['REDIS_PASSWORD'];

            expect($env['CACHE_URL'])->toBe("redis://:{$password}@cache:6379")
                ->and($env['CACHE_HOST'])->toBe('cache')
                ->and($env['CACHE_PORT'])->toBe('6379')
                ->and($env['CACHE_PASSWORD'])->toBe($password);
        });

        it('gets the SMTP address of Mailpit as well as its web address', function () {
            [, $env] = linkedTo('mailpit', 'Inbox', 'MAIL');

            expect($env)->toMatchArray([
                'MAIL_URL' => 'http://inbox:8025',
                'MAIL_SMTP_HOST' => 'inbox',
                'MAIL_SMTP_PORT' => '1025',
            ]);
        });

        it('gets the access key, secret, region and bucket of Garage', function () {
            [$garage, $env] = linkedTo('garage', 'Files', 'S3');
            $vars = $garage->environment();

            expect($env)->toMatchArray([
                'S3_URL' => 'http://files:3900',
                'S3_ACCESS_KEY_ID' => $vars['COMMYPANEL_ACCESS_KEY_ID'],
                'S3_SECRET_ACCESS_KEY' => $vars['COMMYPANEL_SECRET_ACCESS_KEY'],
                'S3_REGION' => 'garage',
                'S3_BUCKET' => 'main',
            ]);
        });

        it('gets the user and password of Gotenberg', function () {
            [$gotenberg, $env] = linkedTo('gotenberg', 'Pdf', 'PDF');

            expect($env['PDF_USER'])->toBe('gotenberg')
                ->and($env['PDF_PASSWORD'])->toBe($gotenberg->environment()['GOTENBERG_API_BASIC_AUTH_PASSWORD']);
        });

        it('gets nothing extra from an app that has nothing to add', function () {
            [, $env] = linkedTo('uptime-kuma', 'Watch', 'KUMA');

            expect($env)->toBe(['KUMA_URL' => 'http://watch:3001', 'KUMA_HOST' => 'watch', 'KUMA_PORT' => '3001']);
        });
    });

    it('shows what to connect with, the secret rows marked', function (string $template, array $rows) {
        $this->post('/apps', quickForm($template, 'Quick One'));
        App::query()->firstOrFail()->forceFill(['setup_step' => null])->save();

        $response = $this->withSession(['auth.password_confirmed_at' => time()])->getJson('/apps/quick-one/credentials')->assertOk();

        expect(collect($response->json('rows'))->map(fn ($r) => [$r['label'], $r['secret']])->all())->toBe($rows);
    })->with([
        'Uptime Kuma' => ['uptime-kuma', [['Address for linked apps', false]]],
        'Mailpit' => ['mailpit', [['SMTP address for linked apps', false], ['Address for linked apps', false], ['User', false], ['Password', true]]],
        'Gotenberg' => ['gotenberg', [['Address for linked apps', false], ['User', false], ['Password', true]]],
        'Redis' => ['redis', [['Address for linked apps', false], ['Password', true], ['Connection URL', true]]],
        'n8n' => ['n8n', [['Address for linked apps', false], ['Encryption key', true]]],
        'Garage' => ['garage', [['Address for linked apps', false], ['Region', false], ['Bucket', false], ['Access key', false], ['Secret key', true]]],
    ]);

    it('has setup scripts that parse', function (string $template) {
        $path = $this->dataPath.'/script.sh';
        File::ensureDirectoryExists($this->dataPath);
        File::put($path, FastApps::find($template)->files(new App(['slug' => 'x']))['commypanel-start.sh']);

        exec('sh -n '.escapeshellarg($path).' 2>&1', $out, $code);

        expect($code)->toBe(0, implode("\n", $out));
    })->with(['redis', 'garage']);

    it('builds Garage from its own image and a plain Alpine, with no secret in the settings file', function () {
        $files = FastApps::find('garage')->files(new App(['slug' => 'files']));

        expect($files['Dockerfile'])->toContain('FROM dxflrs/garage:v2.5.0 AS garage')->toContain('FROM alpine:3.22')
            ->and($files['garage.toml'])->not->toContain('secret')->not->toContain('token')
            ->and($files['garage.toml'])->toContain('replication_factor = 1');
    });
});

describe('the app page', function () {
    it('says what it is made from', function () {
        $this->post('/apps', quickForm('meilisearch', 'Search'));
        App::query()->firstOrFail()->forceFill(['setup_step' => null])->save();

        $this->get('/apps/search')->assertInertia(fn (Assert $page) => $page
            ->where('app.source_type', 'template')
            ->where('app.template.name', 'Meilisearch')
            ->where('app.template.release', 'getmeili/meilisearch:v1.15'));
    });

    it('can change the runtime settings without repository fields', function () {
        $this->post('/apps', quickForm('meilisearch', 'Search'));
        App::query()->firstOrFail()->forceFill(['setup_step' => null])->save();

        $this->put('/apps/search', ['name' => 'Search', 'container_port' => 7700, 'health_path' => '/health', 'memory_limit_mb' => 512])->assertSessionHasNoErrors();

        expect(App::query()->firstOrFail()->memory_limit_mb)->toBe(512);
    });
});
