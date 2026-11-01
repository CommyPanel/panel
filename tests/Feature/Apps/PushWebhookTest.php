<?php

use App\Auth\SyncRolesAndPermissions;
use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

const HOOK = '0123456789abcdef0123456789abcdef';
const SECRET = 'the-webhook-secret';

beforeEach(function () {
    Queue::fake();
    $this->shop = App::factory()->create([
        'slug' => 'shop', 'git_url' => 'https://github.com/acme/shop.git', 'git_branch' => 'main',
        'webhook_id' => HOOK, 'webhook_secret' => SECRET,
    ]);
});

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, string|null>  $headers  null leaves a header out
 */
function push(array $payload = ['ref' => 'refs/heads/main', 'after' => 'abc1234def'], array $headers = [], ?string $signWith = SECRET): TestResponse
{
    $body = json_encode($payload);
    $server = ['CONTENT_TYPE' => 'application/json'];
    $headers += ['X-GitHub-Event' => 'push'];

    if ($signWith !== null) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $signWith);
    }

    foreach (array_filter($headers, fn ($value) => $value !== null) as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', '/webhooks/deploy/'.HOOK, [], [], [], $server, $body);
}

describe('receiving', function () {
    it('deploys on a signed push to the branch, without a session or CSRF token', function () {
        push()->assertStatus(202)->assertJson(['message' => 'Deployment #1 queued.']);

        Queue::assertPushed(RunDeployment::class);
        $audit = AuditLog::query()->where('action', 'app.webhook_deploy')->firstOrFail();
        expect($audit->metadata['commit'])->toBe('abc1234def')
            ->and($audit->metadata['deployment'])->toBe(1);
    });

    it('refuses a wrong, missing or SHA-1 signature', function () {
        push(signWith: 'wrong-secret')->assertForbidden();
        push(signWith: null)->assertForbidden();

        $body = json_encode(['ref' => 'refs/heads/main']);
        $this->call('POST', '/webhooks/deploy/'.HOOK, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE' => 'sha1='.hash_hmac('sha1', $body, SECRET),
        ], $body)->assertForbidden();

        Queue::assertNothingPushed();
    });

    it('answers unknown hooks the same way, whatever they look like', function (string $hook) {
        $this->postJson("/webhooks/deploy/{$hook}", ['ref' => 'refs/heads/main'])->assertNotFound();
    })->with(['shop', 'ffffffffffffffffffffffffffffffff', '../../etc']);

    it('stops working when the webhook is turned off', function () {
        $this->shop->update(['webhook_id' => null, 'webhook_secret' => null]);

        $this->postJson('/webhooks/deploy/'.HOOK, [])->assertNotFound();
    });

    it('accepts GitLab, Bitbucket and Gitea pushes', function (array $payload, array $headers, bool $sign) {
        push($payload, $headers, $sign ? SECRET : null)->assertStatus(202)->assertJson(['message' => 'Deployment #1 queued.']);
    })->with([
        'gitlab' => [['ref' => 'refs/heads/main', 'checkout_sha' => 'abc1234'], ['X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => SECRET, 'X-GitHub-Event' => null], false],
        'bitbucket' => [['push' => ['changes' => [['new' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => 'abc1234']]]]]], ['X-Event-Key' => 'repo:push', 'X-GitHub-Event' => null], true],
        'gitea' => [['ref' => 'refs/heads/main'], ['X-Gitea-Event' => 'push', 'X-GitHub-Event' => null], true],
    ]);

    it('refuses a GitLab call with the wrong token', function () {
        push(['ref' => 'refs/heads/main'], ['X-Gitlab-Token' => 'nope', 'X-GitHub-Event' => null], null)->assertForbidden();
    });

    it('answers the ping GitHub sends when the webhook is added', function () {
        push(['zen' => 'Keep it simple.'], ['X-GitHub-Event' => 'ping'])->assertOk();

        Queue::assertNothingPushed();
    });

    it('ignores what is not a push to the branch', function (array $payload, array $headers, string $reason) {
        push($payload, $headers)->assertStatus(202)->assertJson(['message' => "Ignored: {$reason}"]);

        Queue::assertNothingPushed();
    })->with([
        'other branch' => [['ref' => 'refs/heads/dev'], [], 'Not a push to main.'],
        'tag' => [['ref' => 'refs/tags/v1'], [], 'Not a push to main.'],
        'deleted branch' => [['ref' => 'refs/heads/main', 'deleted' => true], [], 'Not a push to main.'],
        'issue event' => [['ref' => 'refs/heads/main'], ['X-GitHub-Event' => 'issues'], 'Not a push event.'],
    ]);

    it('does not wake a stopped app or stack up queued deployments', function () {
        $live = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Active]);
        $this->shop->update(['active_deployment_id' => $live->id, 'stopped_at' => now()]);
        push()->assertJson(['message' => 'Ignored: The app is stopped; start it in CommyPanel first.']);

        $this->shop->update(['stopped_at' => null]);
        Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 2, 'status' => DeploymentStatus::Queued]);
        push()->assertJson(['message' => 'Ignored: A deployment is already waiting to run.']);

        Queue::assertNothingPushed();
    });

    it('is rate limited per sender', function () {
        for ($i = 0; $i < 30; $i++) {
            push(signWith: 'wrong');
        }

        push()->assertStatus(429);
    });
});

describe('managing', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
        $this->shop->update(['webhook_id' => null, 'webhook_secret' => null]);
    });

    it('turns the webhook on with a random address and secret, and shows them', function () {
        $this->post('/apps/shop/webhook')->assertSessionHasNoErrors();

        $app = $this->shop->fresh();
        expect($app->webhook_id)->toMatch('/^[0-9a-f]{32}$/')
            ->and(strlen((string) $app->webhook_secret))->toBe(48);

        $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page
            ->where('webhook.enabled', true)
            ->where('webhook.url', url('/webhooks/deploy/'.$app->webhook_id))
            ->where('webhook.secret', $app->webhook_secret)
            ->where('webhook.provider', 'GitHub')
            ->where('webhook.on_panel_domain', false));
    });

    it('gives a new secret but keeps the address, and the old secret stops working', function () {
        $this->post('/apps/shop/webhook');
        $first = $this->shop->fresh();

        $this->post('/apps/shop/webhook');
        $second = $this->shop->fresh();

        expect($second->webhook_id)->toBe($first->webhook_id)
            ->and($second->webhook_secret)->not->toBe($first->webhook_secret)
            ->and(AuditLog::query()->where('action', 'app.webhook_secret_renewed')->exists())->toBeTrue();
    });

    it('turns it off', function () {
        $this->post('/apps/shop/webhook');
        $this->delete('/apps/shop/webhook')->assertSessionHasNoErrors();

        expect($this->shop->fresh())->webhook_id->toBeNull()->webhook_secret->toBeNull();
    });

    it('hides the secret from people who may only view the app', function () {
        $this->post('/apps/shop/webhook');
        app(SyncRolesAndPermissions::class)();
        $viewer = User::factory()->regular()->create();
        $viewer->assignRole(Role::findOrCreate('viewer', 'web')->syncPermissions(['apps.view']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($viewer);

        $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page
            ->where('webhook.enabled', true)
            ->where('webhook.secret', null));
    });

    it('is only for finished Git apps', function () {
        App::factory()->create(['slug' => 'upload', 'source_type' => 'upload', 'git_url' => null]);
        App::factory()->create(['slug' => 'draft', 'setup_step' => 'source']);

        $this->post('/apps/upload/webhook')->assertNotFound();
        $this->post('/apps/draft/webhook')->assertNotFound();
    });
});
