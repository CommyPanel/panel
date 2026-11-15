<?php

use App\Audit\Severity;
use App\Deploy\Deployer;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\TemplateSourceFetcher;
use App\Deploy\Stacks\StackDetector;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\Enums\Stack;
use App\FastApps\FastApps;
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
    it('lists the five apps, warns about Elasticsearch and offers Kibana the Elasticsearch apps', function () {
        finishedElasticsearch('Logs');

        $this->get('/apps/create')->assertInertia(fn (Assert $page) => $page
            ->component('apps/create')
            ->where('fast_apps', fn ($apps) => collect($apps)->pluck('id')->all() === ['meilisearch', 'typesense', 'grafana', 'elasticsearch', 'kibana'])
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
            ->and(File::get("{$dir}/Dockerfile"))->toStartWith("FROM {$image}\n");

        $plan = app(StackDetector::class)->plan($dir, 80);

        expect($plan->stack)->toBe(Stack::Template)->and($plan->dockerfile)->toBeNull();
    })->with([
        'Meilisearch' => ['meilisearch', 'getmeili/meilisearch:v1.15'],
        'Typesense' => ['typesense', 'typesense/typesense:28.0'],
        'Grafana' => ['grafana', 'grafana/grafana:12.2.0'],
        'Elasticsearch' => ['elasticsearch', 'docker.elastic.co/elasticsearch/elasticsearch:8.19.0'],
        'Kibana' => ['kibana', 'docker.elastic.co/kibana/kibana:8.19.0'],
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

        foreach (['meilisearch', 'typesense', 'grafana', 'elasticsearch'] as $template) {
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
