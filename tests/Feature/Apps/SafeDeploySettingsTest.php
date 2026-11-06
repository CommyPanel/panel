<?php

use App\Alerts\AlertSettings;
use App\Alerts\DeployAlerts;
use App\Auth\SyncRolesAndPermissions;
use App\Dns\DnsResolver;
use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDnsResolver;

beforeEach(function () {
    app(SyncRolesAndPermissions::class)();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->shop = App::factory()->create(['slug' => 'shop', 'name' => 'Shop', 'setup_step' => null]);
    $this->form = [
        'watch_enabled' => true, 'watch_minutes' => 10,
        'rollout_enabled' => false, 'rollout_steps' => [10, 50], 'rollout_hold_minutes' => 5, 'rollout_auto' => true,
    ];
});

it('starts with watching on and a gradual rollout off', function () {
    $fresh = App::factory()->create(['slug' => 'fresh'])->fresh();

    expect($fresh->watch_enabled)->toBeTrue()->and($fresh->watch_minutes)->toBe(5)
        ->and($fresh->rollout_enabled)->toBeFalse()->and($fresh->rollout_hold_minutes)->toBe(5)->and($fresh->rollout_auto)->toBeTrue();
});

it('saves the settings, puts the steps in order, and records what changed', function () {
    $this->put('/apps/shop/safe-deploys', [...$this->form, 'watch_enabled' => false, 'rollout_steps' => [50, 10, 25]])->assertSessionHasNoErrors();

    $shop = $this->shop->fresh();
    expect($shop->watch_enabled)->toBeFalse()->and($shop->watch_minutes)->toBe(10)->and($shop->rollout_steps)->toBe([10, 25, 50]);
    $entry = AuditLog::query()->where('action', 'app.safe_deploys_changed')->firstOrFail();
    expect($entry->metadata['from']['watch'])->toBe('5 min')->and($entry->metadata['to']['watch'])->toBe('off');
});

it('makes a person confirm they understand before a gradual rollout is turned on, but not every time after', function () {
    $this->put('/apps/shop/safe-deploys', [...$this->form, 'rollout_enabled' => true])->assertSessionHasErrors('understood');
    expect($this->shop->fresh()->rollout_enabled)->toBeFalse();

    $this->put('/apps/shop/safe-deploys', [...$this->form, 'rollout_enabled' => true, 'understood' => true])->assertSessionHasNoErrors();
    expect($this->shop->fresh()->rollout_enabled)->toBeTrue();

    $this->put('/apps/shop/safe-deploys', [...$this->form, 'rollout_enabled' => true, 'watch_minutes' => 15])->assertSessionHasNoErrors();
    expect($this->shop->fresh()->watch_minutes)->toBe(15);
});

it('refuses settings that make no sense', function (array $change, string $field) {
    $this->put('/apps/shop/safe-deploys', [...$this->form, 'understood' => true, ...$change])->assertSessionHasErrors($field);
})->with([
    'no watch time' => [['watch_minutes' => 0], 'watch_minutes'],
    'a day of watching' => [['watch_minutes' => 1440], 'watch_minutes'],
    'no steps' => [['rollout_steps' => []], 'rollout_steps'],
    'five steps' => [['rollout_steps' => [5, 10, 20, 40, 80]], 'rollout_steps'],
    'a hundred percent' => [['rollout_steps' => [10, 100]], 'rollout_steps.1'],
    'zero percent' => [['rollout_steps' => [0, 50]], 'rollout_steps.0'],
    'the same step twice' => [['rollout_steps' => [10, 10]], 'rollout_steps.0'],
    'a hold of zero' => [['rollout_hold_minutes' => 0], 'rollout_hold_minutes'],
    'a hold of a day' => [['rollout_hold_minutes' => 1440], 'rollout_hold_minutes'],
]);

it('needs the permission to change the app', function () {
    $viewer = User::factory()->regular()->create();
    $viewer->givePermissionTo('apps.view');

    $this->actingAs($viewer)->put('/apps/shop/safe-deploys', $this->form)->assertForbidden();
});

it('says in a message when a release was rolled back by itself, and why', function () {
    Http::fake(['*' => Http::response('ok')]);
    app(AlertSettings::class)->save(['enabled' => true, 'format' => 'json', 'events' => 'failures', 'url' => 'https://hooks.example.com/x']);
    $deployment = Deployment::factory()->create([
        'app_id' => $this->shop->id, 'number' => 9, 'status' => DeploymentStatus::RolledBack,
        'error' => 'The error rate (5xx) is 20% over 300 requests.',
    ]);
    app()->instance(DnsResolver::class, new FakeDnsResolver(['hooks.example.com' => ['a' => ['203.0.113.10']]]));

    app(DeployAlerts::class)->deployment($deployment);

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->data()['text'], 'Shop (#9) was rolled back automatically')
        && str_contains($r->data()['text'], 'error rate (5xx) is 20%')
        && $r->data()['event'] === 'deployment.rolled_back' && $r->data()['succeeded'] === false);
});

it('shows the settings and the state of watches on the pages', function () {
    $this->get('/apps/shop')->assertOk();
});
