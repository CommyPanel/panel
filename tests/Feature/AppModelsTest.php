<?php

use App\Deploy\DeployLogger;
use App\Enums\DeploymentStatus;
use App\Enums\Stack;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\Deployment;
use Illuminate\Support\Facades\DB;

it('generates unique, container-safe slugs', function () {
    expect(App::generateSlug('My Shop!'))->toBe('my-shop');

    App::factory()->create(['slug' => 'my-shop']);
    App::factory()->create(['slug' => 'my-shop-2']);

    expect(App::generateSlug('My Shop'))->toBe('my-shop-3')
        ->and(App::generateSlug('!!!'))->toBe('app')
        ->and(strlen(App::generateSlug(str_repeat('long name ', 20))))->toBeLessThanOrEqual(App::SLUG_MAX_LENGTH);
});

it('numbers deployments per app', function () {
    $app = App::factory()->create();
    $other = App::factory()->create();

    expect($app->nextDeploymentNumber())->toBe(1);

    Deployment::factory()->create(['app_id' => $app->id, 'number' => 1]);
    Deployment::factory()->create(['app_id' => $app->id, 'number' => 2]);
    Deployment::factory()->create(['app_id' => $other->id, 'number' => 1]);

    expect($app->nextDeploymentNumber())->toBe(3)
        ->and($other->nextDeploymentNumber())->toBe(2);
});

it('encrypts environment values and deploy keys at rest', function () {
    $app = App::factory()->create(['deploy_key_private' => 'PRIVATE-KEY-MATERIAL']);
    AppEnvVar::query()->create(['app_id' => $app->id, 'key' => 'DB_PASSWORD', 'value' => 'hunter2']);

    $rawEnv = DB::table('app_env_vars')->value('value');
    $rawKey = DB::table('apps')->value('deploy_key_private');

    expect($rawEnv)->not->toContain('hunter2')
        ->and($rawKey)->not->toContain('PRIVATE-KEY-MATERIAL')
        ->and($app->fresh()->environment())->toBe(['DB_PASSWORD' => 'hunter2'])
        ->and($app->fresh()->deploy_key_private)->toBe('PRIVATE-KEY-MATERIAL');
});

it('never serialises secrets', function () {
    $app = App::factory()->create(['deploy_key_private' => 'PRIVATE-KEY-MATERIAL', 'git_token' => 'TOKEN-MATERIAL']);
    $var = AppEnvVar::query()->create(['app_id' => $app->id, 'key' => 'A', 'value' => 'secret']);

    expect(json_encode($app))->not->toContain('PRIVATE-KEY-MATERIAL')->not->toContain('TOKEN-MATERIAL')
        ->and(json_encode($var))->not->toContain('secret');
});

it('casts enums and keeps deployment logs', function () {
    $app = App::factory()->create(['stack' => Stack::Dotnet]);
    $deployment = Deployment::factory()->create(['app_id' => $app->id]);

    $logger = new DeployLogger($deployment, flushEvery: 2);
    $logger->line('Step 1');
    $logger->line('Step 2');

    expect($app->fresh()->stack)->toBe(Stack::Dotnet)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Queued)
        ->and($deployment->fresh()->log)->toBe("Step 1\nStep 2\n")
        ->and(DeploymentStatus::Failed->isFinished())->toBeTrue()
        ->and(DeploymentStatus::Building->isFinished())->toBeFalse();
});

it('deletes deployments and variables with their app', function () {
    $app = App::factory()->create();
    Deployment::factory()->create(['app_id' => $app->id]);
    AppEnvVar::query()->create(['app_id' => $app->id, 'key' => 'A', 'value' => 'x']);

    $app->delete();

    expect(Deployment::query()->count())->toBe(0)
        ->and(AppEnvVar::query()->count())->toBe(0);
});
