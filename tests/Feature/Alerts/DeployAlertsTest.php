<?php

use App\Alerts\AlertEvent;
use App\Alerts\AlertKind;
use App\Alerts\DeployAlerts;
use App\Enums\DeploymentStatus;
use App\Jobs\SendAlertJob;
use App\Models\AlertTarget;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    AlertTarget::query()->create(['name' => 'Chat', 'kind' => AlertKind::Slack, 'address' => 'https://hooks.example.com/x', 'events' => AlertEvent::keys()]);
    $this->deployment = fn (DeploymentStatus $status) => Deployment::factory()->create([
        'app_id' => App::factory()->create(['slug' => 'shop', 'name' => 'Shop'])->id,
        'number' => 7, 'status' => $status, 'error' => 'boom', 'commit_sha' => 'abcdef1234567890',
    ]);
});

it('maps the end of a deployment to its event', function (DeploymentStatus $status, string $event) {
    DeployAlerts::dispatchFor(($this->deployment)($status));

    Queue::assertPushed(SendAlertJob::class, fn ($job) => $job->message->event->value === $event && $job->message->app === 'shop');
})->with([
    'active' => [DeploymentStatus::Active, 'deployment.succeeded'],
    'superseded' => [DeploymentStatus::Superseded, 'deployment.succeeded'],
    'failed' => [DeploymentStatus::Failed, 'deployment.failed'],
    'rolled back' => [DeploymentStatus::RolledBack, 'deployment.rolled_back'],
]);

it('says nothing about a deployment that is still running', function () {
    DeployAlerts::dispatchFor(($this->deployment)(DeploymentStatus::Building));

    Queue::assertNothingPushed();
});
