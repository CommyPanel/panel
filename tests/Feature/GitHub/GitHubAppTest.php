<?php

use App\Auth\SyncRolesAndPermissions;
use App\Deploy\Source\GitCredentials;
use App\Dns\PanelDomain;
use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\GitHub\GitHubApp;
use App\GitHub\GitHubFailed;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app(SyncRolesAndPermissions::class)();
    Cache::flush();
    $this->user = User::factory()->create();
    $this->confirmed = fn () => $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);

    $panel = app(PanelDomain::class);
    $panel->set('panel.example.com');
    $panel->markIssued(Carbon::now()->addDays(60));

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $this->pem);

    // An app GitHub "created" for us
    $this->convertResponse = ['id' => 4242, 'slug' => 'commypanel-abc12', 'name' => 'CommyPanel abc12', 'pem' => $this->pem, 'webhook_secret' => 'whsec_shared', 'client_secret' => 'ignored'];

    // Walks the real flow up to a created app.
    $this->create = function () {
        ($this->confirmed)()->post('/settings/github/prepare', ['account' => 'personal'])->assertSessionHasNoErrors();
        $state = session('github.pending')['state'];
        Http::fake(['api.github.com/app-manifests/*' => Http::response($this->convertResponse, 201)]);
        $this->actingAs($this->user)->get('/settings/github/callback?code=abcdefghij1234&state='.$state)->assertSessionHasNoErrors();
    };
    $this->install = function () {
        Http::fake([
            'api.github.com/app/installations/99/access_tokens' => Http::response(['token' => 'ghs_installationtoken1234'], 201),
            'api.github.com/app/installations?*' => Http::response([['id' => 99, 'account' => ['login' => 'acme']]]),
            'api.github.com/installation/repositories*' => Http::response(['repositories' => [
                ['full_name' => 'acme/shop', 'default_branch' => 'develop', 'private' => true],
                ['full_name' => 'acme/blog', 'default_branch' => 'main', 'private' => false],
                ['full_name' => '../evil', 'default_branch' => 'main', 'private' => false],
            ]]),
        ]);
        $this->actingAs($this->user)->get('/settings/github/installed')->assertSessionHasNoErrors();
    };
});

describe('creating the app', function () {
    it('needs the permission, the password, and a panel reachable over https', function () {
        $nobody = User::factory()->regular()->create();
        $this->actingAs($nobody)->get('/settings/github')->assertForbidden();
        $this->actingAs($nobody)->post('/settings/github/prepare', ['account' => 'personal'])->assertForbidden();

        $this->actingAs($this->user)->post('/settings/github/prepare', ['account' => 'personal'])->assertRedirect();
        expect(session('github.pending'))->toBeNull();

        Setting::query()->where('key', 'like', 'panel.%')->delete();
        app(PanelDomain::class)->set(null);
        ($this->confirmed)()->post('/settings/github/prepare', ['account' => 'personal'])->assertSessionHasErrors('github');
    });

    it('hands the page a manifest with the panel\'s own https addresses, and no secrets', function () {
        ($this->confirmed)()->post('/settings/github/prepare', ['account' => 'organization', 'organization' => 'acme'])->assertSessionHasNoErrors();

        $this->get('/settings/github')->assertInertia(function (Assert $page) {
            $page->where('start.action', 'https://github.com/organizations/acme/settings/apps/new');
            $manifest = json_decode($page->toArray()['props']['start']['manifest'], true);

            expect($manifest['hook_attributes']['url'])->toBe('https://panel.example.com/webhooks/github')
                ->and($manifest['redirect_url'])->toBe('https://panel.example.com/settings/github/callback')
                ->and($manifest['default_permissions'])->toBe(['contents' => 'read', 'metadata' => 'read'])
                ->and($manifest['public'])->toBeFalse()
                ->and($manifest['default_events'])->toBe(['push']);
        });
    });

    it('refuses an organization name that is not a name', function (string $name) {
        ($this->confirmed)()->post('/settings/github/prepare', ['account' => 'organization', 'organization' => $name])->assertSessionHasErrors('organization');
    })->with(['slash' => 'acme/../x', 'dots' => 'a.b', 'long' => str_repeat('a', 60), 'empty' => '']);

    it('turns the code into a connected app and keeps the key and secret encrypted', function () {
        ($this->create)();

        expect(app(GitHubApp::class)->connected())->toBeTrue()
            ->and(Setting::read('github.app'))->not->toContain('BEGIN')->not->toContain('whsec_shared')->not->toContain('client_secret')
            ->and(app(GitHubApp::class)->webhookSecret())->toBe('whsec_shared')
            ->and(json_encode(app(GitHubApp::class)->toArray()))->not->toContain('whsec_shared')->not->toContain('BEGIN')
            ->and(AuditLog::query()->where('action', 'github.connected')->exists())->toBeTrue();

        // GitHub rejects the conversion with a 422 when it is sent a body (even "[]"), so it carries none
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/app-manifests/abcdefghij1234/conversions') && $r->method() === 'POST' && $r->body() === '');
    });

    it('accepts the return only with this session\'s state, once', function () {
        ($this->confirmed)()->post('/settings/github/prepare', ['account' => 'personal']);
        $state = session('github.pending')['state'];
        Http::fake(['*' => Http::response($this->convertResponse, 201)]);

        $this->get('/settings/github/callback?code=abcdefghij1234&state=wrong')->assertSessionHasErrors('github');
        expect(app(GitHubApp::class)->connected())->toBeFalse();
        // a wrong try also uses up the state
        $this->get('/settings/github/callback?code=abcdefghij1234&state='.$state)->assertSessionHasErrors('github');
        $this->get('/settings/github/callback?code=abcdefghij1234')->assertSessionHasErrors('github');
        Http::assertNothingSent();
    });

    it('ignores a return after ten minutes and a code that is not a code', function () {
        ($this->confirmed)()->post('/settings/github/prepare', ['account' => 'personal']);
        $state = session('github.pending')['state'];
        session(['github.pending' => [...session('github.pending'), 'at' => time() - 700]]);
        Http::fake();
        $this->get('/settings/github/callback?code=abcdefghij1234&state='.$state)->assertSessionHasErrors('github');

        ($this->confirmed)()->post('/settings/github/prepare', ['account' => 'personal']);
        $state = session('github.pending')['state'];
        $this->get('/settings/github/callback?code='.urlencode('../../x').'&state='.$state)->assertSessionHasErrors('github');
        Http::assertNothingSent();
    });

    it('tells the person what GitHub said when it refuses', function () {
        ($this->confirmed)()->post('/settings/github/prepare', ['account' => 'personal']);
        $state = session('github.pending')['state'];
        Http::fake(['*' => Http::response(['message' => 'Validation Failed'], 422)]);

        $this->get('/settings/github/callback?code=abcdefghij1234&state='.$state)
            ->assertSessionHasErrors(['github' => 'GitHub answered 422 when asked to create the app: Validation Failed.']);
    });

    it('refuses an answer that is not an app', function () {
        ($this->confirmed)()->post('/settings/github/prepare', ['account' => 'personal']);
        $state = session('github.pending')['state'];
        Http::fake(['*' => Http::response([...$this->convertResponse, 'pem' => 'not a key'], 201)]);

        $this->get('/settings/github/callback?code=abcdefghij1234&state='.$state)->assertSessionHasErrors('github');
        expect(app(GitHubApp::class)->connected())->toBeFalse();
    });
});

describe('installing and listing', function () {
    it('looks the installation up with the app\'s own signed token, not with the request', function () {
        ($this->create)();
        ($this->install)();

        expect(app(GitHubApp::class)->installed())->toBeTrue()->and(app(GitHubApp::class)->toArray()['account'])->toBe('acme');

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/app/installations?per_page=10')) {
                return false;
            }
            [$h, $p, $s] = explode('.', str_replace('Bearer ', '', $r->header('Authorization')[0]));
            $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);

            return json_decode(base64_decode(strtr($h, '-_', '+/')), true)['alg'] === 'RS256'
                && $payload['iss'] === 4242 && $payload['exp'] - $payload['iat'] <= 600
                && openssl_verify("$h.$p", base64_decode(strtr($s, '-_', '+/')), openssl_pkey_get_details(openssl_pkey_get_private($this->pem))['key'], OPENSSL_ALGO_SHA256) === 1;
        });
    });

    it('lists only well-formed repositories', function () {
        ($this->create)();
        ($this->install)();

        $names = array_column(app(GitHubApp::class)->repositories(), 'name');
        expect($names)->toBe(['acme/blog', 'acme/shop']);
    });

    it('mints a token for one repository, read only, and fails clearly when GitHub refuses', function () {
        ($this->create)();
        ($this->install)();

        expect(app(GitHubApp::class)->installationToken('acme/shop'))->toBe('ghs_installationtoken1234');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/access_tokens') && $r->data() === ['repositories' => ['shop'], 'permissions' => ['contents' => 'read']]);

        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);
        expect(fn () => app(GitHubApp::class)->installationToken('acme/shop'))->toThrow(GitHubFailed::class, 'uninstalled');
        expect(fn () => app(GitHubApp::class)->installationToken('acme/../x'))->toThrow(GitHubFailed::class);
    });

    it('never follows a redirect and never puts a token in an error', function () {
        ($this->create)();
        ($this->install)();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.example.com/'])]);

        try {
            app(GitHubApp::class)->installationToken('acme/shop');
            $this->fail('A redirect must be an error.');
        } catch (GitHubFailed $e) {
            expect($e->getMessage())->not->toContain('ghs_')->not->toContain('BEGIN');
        }
        Http::assertSentCount(1);
    });

    it('disconnects with the password and forgets everything', function () {
        ($this->create)();
        $this->flushSession();
        $this->actingAs($this->user)->delete('/settings/github')->assertRedirect();
        expect(app(GitHubApp::class)->connected())->toBeTrue();

        ($this->confirmed)()->delete('/settings/github');
        expect(app(GitHubApp::class)->connected())->toBeFalse()->and(Setting::read('github.app'))->toBeNull();
    });
});

describe('apps read through GitHub', function () {
    beforeEach(function () {
        ($this->create)();
        ($this->install)();
    });

    it('creates the app from a repository GitHub lists, with the clone address derived by the panel', function () {
        $this->actingAs($this->user)->post('/apps', ['name' => 'Shop', 'source' => 'github', 'github_repo' => 'acme/shop', 'git_branch' => 'develop', 'git_url' => 'https://evil.example.com/x.git'])
            ->assertSessionHasNoErrors();

        $app = App::query()->firstOrFail();
        expect($app->github_repo)->toBe('acme/shop')
            ->and($app->git_url)->toBe('https://github.com/acme/shop.git')
            ->and($app->source_type->value)->toBe('git');
    });

    it('refuses a repository the installation was not given', function () {
        $this->actingAs($this->user)->post('/apps', ['name' => 'Other', 'source' => 'github', 'github_repo' => 'someone/private', 'git_branch' => 'main'])
            ->assertSessionHasErrors('github_repo');
        expect(App::query()->count())->toBe(0);
    });

    it('clones with a token for that repository', function () {
        $app = App::factory()->create(['github_repo' => 'acme/shop', 'git_url' => 'https://github.com/acme/shop.git']);

        $credentials = GitCredentials::forApp($app);

        expect($credentials->isToken())->toBeTrue()->and($credentials->username)->toBe('x-access-token')->and($credentials->token)->toBe('ghs_installationtoken1234');
    });
});

describe('the webhook', function () {
    beforeEach(function () {
        Queue::fake();
        ($this->create)();
        ($this->install)();
        $this->shop = App::factory()->create(['slug' => 'shop', 'github_repo' => 'acme/shop', 'git_branch' => 'develop', 'git_url' => 'https://github.com/acme/shop.git', 'setup_step' => null, 'github_auto_deploy' => true]);
        $this->push = function (array $payload = [], ?string $secret = 'whsec_shared', string $event = 'push') {
            $payload = [...['ref' => 'refs/heads/develop', 'after' => str_repeat('a', 40), 'repository' => ['full_name' => 'acme/shop'], 'installation' => ['id' => 99]], ...$payload];
            $body = json_encode($payload);

            return $this->call('POST', '/webhooks/github', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GITHUB_EVENT' => $event,
                ...($secret === null ? [] : ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret)]),
            ], $body);
        };
    });

    it('deploys the app whose repository and branch were pushed', function () {
        ($this->push)()->assertStatus(202);

        expect(Deployment::query()->where('app_id', $this->shop->id)->count())->toBe(1);
        Queue::assertPushed(RunDeployment::class);
        expect(AuditLog::query()->where('action', 'app.webhook_deploy')->exists())->toBeTrue();
    });

    it('matches the repository name without regard to case', function () {
        ($this->push)(['repository' => ['full_name' => 'ACME/Shop']])->assertStatus(202);
        expect(Deployment::query()->count())->toBe(1);
    });

    it('refuses a wrong, missing or SHA-1 signature', function () {
        ($this->push)([], 'wrong')->assertForbidden();
        ($this->push)([], null)->assertForbidden();
        $body = json_encode(['ref' => 'refs/heads/develop']);
        $this->call('POST', '/webhooks/github', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => 'sha1='.hash_hmac('sha1', $body, 'whsec_shared'), 'HTTP_X_GITHUB_EVENT' => 'push'], $body)->assertForbidden();
        // the secret of an app's own per-app webhook is not this one
        $this->call('POST', '/webhooks/github', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITLAB_TOKEN' => 'whsec_shared', 'HTTP_X_GITHUB_EVENT' => 'push'], $body)->assertForbidden();

        expect(Deployment::query()->count())->toBe(0);
    });

    it('ignores other branches, other events, other installations and other repositories', function () {
        ($this->push)(['ref' => 'refs/heads/main'])->assertStatus(202);
        ($this->push)(['ref' => 'refs/tags/v1'])->assertStatus(202);
        ($this->push)(['installation' => ['id' => 7]])->assertStatus(202);
        ($this->push)(['repository' => ['full_name' => 'acme/blog']])->assertStatus(202);
        ($this->push)(['repository' => ['full_name' => '../x']])->assertStatus(202);
        ($this->push)([], 'whsec_shared', 'issues')->assertStatus(202);

        expect(Deployment::query()->count())->toBe(0);
    });

    it('answers the ping and does not wake a stopped app or stack up deployments', function () {
        ($this->push)([], 'whsec_shared', 'ping')->assertOk();
        expect(Deployment::query()->count())->toBe(0);

        ($this->push)()->assertStatus(202);
        ($this->push)()->assertStatus(202);
        expect(Deployment::query()->where('status', DeploymentStatus::Queued)->count())->toBe(1);

        $this->shop->forceFill(['stopped_at' => now()])->save();
        Deployment::query()->delete();
        ($this->push)()->assertStatus(202);
        expect(Deployment::query()->count())->toBe(App::query()->find($this->shop->id)->status() === AppStatus::Stopped ? 0 : 1);
    });

    it('does nothing for an app that has deploy on push off', function () {
        $this->shop->forceFill(['github_auto_deploy' => false])->save();

        ($this->push)()->assertStatus(202);

        expect(Deployment::query()->count())->toBe(0);
    });

    it('does not answer when GitHub is not connected', function () {
        app(GitHubApp::class)->disconnect();

        ($this->push)()->assertNotFound();
    });
});

describe('the deploy on push switch', function () {
    beforeEach(function () {
        $this->shop = App::factory()->create(['slug' => 'shop', 'github_repo' => 'acme/shop', 'git_branch' => 'develop', 'setup_step' => null]);
    });

    it('is off for a new app and is changed, audited, by people who may update apps', function () {
        expect($this->shop->fresh()->github_auto_deploy)->toBeFalse();

        $this->actingAs($this->user)->put('/apps/shop/auto-deploy', ['enabled' => true])->assertSessionHasNoErrors();
        expect($this->shop->fresh()->github_auto_deploy)->toBeTrue()
            ->and(AuditLog::query()->where('action', 'app.auto_deploy_enabled')->exists())->toBeTrue();

        $this->actingAs($this->user)->put('/apps/shop/auto-deploy', ['enabled' => false]);
        expect($this->shop->fresh()->github_auto_deploy)->toBeFalse()
            ->and(AuditLog::query()->where('action', 'app.auto_deploy_disabled')->exists())->toBeTrue();
    });

    it('needs the update permission and only exists for apps read through GitHub', function () {
        $viewer = User::factory()->regular()->create();
        $viewer->givePermissionTo('apps.view');
        $this->actingAs($viewer)->put('/apps/shop/auto-deploy', ['enabled' => true])->assertForbidden();

        App::factory()->create(['slug' => 'plain', 'setup_step' => null]);
        $this->actingAs($this->user)->put('/apps/plain/auto-deploy', ['enabled' => true])->assertNotFound();
        $this->actingAs($this->user)->put('/apps/shop/auto-deploy', ['enabled' => 'maybe'])->assertSessionHasErrors('enabled');
    });

    it('is what the app page shows instead of an address and a secret', function () {
        $this->actingAs($this->user)->get('/apps/shop')->assertInertia(fn (Assert $page) => $page
            ->where('webhook.github', true)->where('webhook.enabled', false)->where('webhook.branch', 'develop')->missing('webhook.secret')->missing('webhook.url'));
    });
});
