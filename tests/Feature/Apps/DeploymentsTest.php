<?php

use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->shop = App::factory()->create(['slug' => 'shop']);
});

it('queues a deployment and redirects to its log', function () {
    Queue::fake();

    $this->post('/apps/shop/deployments')->assertRedirect('/apps/shop/deployments/1');

    Queue::assertPushed(RunDeployment::class);
    expect($this->shop->deployments()->first())->status->toBe(DeploymentStatus::Queued);
});

it('refuses to start a second deployment while one is running', function (DeploymentStatus $status) {
    Queue::fake();
    Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => $status]);

    $this->post('/apps/shop/deployments')->assertSessionHasErrors('deploy');

    Queue::assertNothingPushed();
})->with([DeploymentStatus::Queued, DeploymentStatus::Building, DeploymentStatus::Starting]);

it('shows a deployment with its log', function () {
    Deployment::factory()->create([
        'app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Building,
        'commit_sha' => 'abcdef1234567890', 'log' => "Step 1\nStep 2\n",
    ]);

    $this->get('/apps/shop/deployments/1')->assertInertia(fn (Assert $page) => $page
        ->component('apps/deployment')
        ->where('app.slug', 'shop')
        ->where('deployment.status', 'building')
        ->where('deployment.finished', false)
        ->where('deployment.commit', 'abcdef12')
        ->where('deployment.log', "Step 1\nStep 2\n"));
});

it('does not show another app\'s deployment under this app', function () {
    $other = App::factory()->create();
    Deployment::factory()->create(['app_id' => $other->id, 'number' => 1]);

    $this->get('/apps/shop/deployments/1')->assertNotFound();
});

it('queues a rollback to an earlier release', function () {
    Queue::fake();
    $old = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Superseded, 'image_tag' => 'commypanel/shop:1']);
    $live = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 2, 'status' => DeploymentStatus::Active, 'image_tag' => 'commypanel/shop:2']);
    $this->shop->update(['active_deployment_id' => $live->id]);

    $this->post('/apps/shop/deployments/1/rollback')->assertRedirect('/apps/shop/deployments/3');

    expect($this->shop->deployments()->where('number', 3)->first())->rolled_back_from_id->toBe($old->id);
    Queue::assertPushed(RunDeployment::class);
});

it('explains why a rollback is impossible', function () {
    Queue::fake();
    $gone = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Superseded, 'image_tag' => 'commypanel/shop:1', 'image_available' => false]);

    $this->post('/apps/shop/deployments/1/rollback')->assertSessionHasErrors(['deploy' => 'That release is no longer available to roll back to.']);

    Queue::assertNothingPushed();
});

it('queues a deployment without the cache when asked, for any app', function () {
    Queue::fake();

    $this->post('/apps/shop/deployments', ['fresh' => 'maybe'])->assertSessionHasErrors('fresh');

    $this->post('/apps/shop/deployments', ['fresh' => true])->assertRedirect('/apps/shop/deployments/1');

    expect($this->shop->deployments()->first()->fresh_build)->toBeTrue();
});
