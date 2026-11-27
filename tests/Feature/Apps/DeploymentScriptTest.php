<?php

use App\Auth\SyncRolesAndPermissions;
use App\Deploy\ReleaseScripts;
use App\Enums\Stack;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app(SyncRolesAndPermissions::class)();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function scriptApp(Stack $stack, array $attributes = []): App
{
    return App::factory()->create(['slug' => 'shop', 'name' => 'Shop', 'setup_step' => null, 'stack' => $stack, ...$attributes]);
}

it('is offered for the stacks whose image has a shell, and not for Go or a quick install', function (Stack $stack, bool $supported) {
    expect(ReleaseScripts::supports($stack))->toBe($supported);

    scriptApp($stack);
    $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page->where('app.has_release_script', $supported));
})->with([
    'laravel' => [Stack::Laravel, true],
    'node' => [Stack::Node, true],
    'python' => [Stack::Python, true],
    'dotnet' => [Stack::Dotnet, true],
    'dockerfile' => [Stack::Dockerfile, true],
    'go' => [Stack::Go, false],
    'quick install' => [Stack::Template, false],
]);

it('saves the script with shell line breaks, replaces the single command and records only the line counts', function () {
    $app = scriptApp(Stack::Node, ['release_command' => 'npm run migrate']);

    $this->put('/apps/shop/script', ['release_script' => "npx prisma migrate deploy\r\nnpm run seed  \r\n"])->assertSessionHasNoErrors();

    $app->refresh();
    expect($app->release_script)->toBe("npx prisma migrate deploy\nnpm run seed\n")
        ->and($app->release_command)->toBeNull();

    $entry = AuditLog::query()->where('action', 'app.script_changed')->firstOrFail();
    expect($entry->metadata['lines_before'])->toBe(0)
        ->and($entry->metadata['lines_after'])->toBe(2)
        ->and(json_encode($entry->metadata))->not->toContain('prisma');
});

it('removes the script when it is emptied', function () {
    $app = scriptApp(Stack::Python, ['release_script' => "python manage.py migrate\n"]);

    $this->put('/apps/shop/script', ['release_script' => "  \n"])->assertSessionHasNoErrors();

    expect($app->fresh()->release_script)->toBeNull();
});

it('refuses a script that is too long or is not text, and one for a stack without the page', function () {
    scriptApp(Stack::Node);

    $this->put('/apps/shop/script', ['release_script' => str_repeat('a', ReleaseScripts::MAX_LENGTH + 1)])->assertSessionHasErrors('release_script');
    $this->put('/apps/shop/script', ['release_script' => "echo\0hi"])->assertSessionHasErrors('release_script');

    App::query()->update(['stack' => Stack::Go]);
    $this->put('/apps/shop/script', ['release_script' => 'true'])->assertNotFound();
});

it('needs the permission to update apps', function () {
    scriptApp(Stack::Node);
    $this->actingAs(User::factory()->create(['is_super' => false]));

    $this->put('/apps/shop/script', ['release_script' => 'true'])->assertForbidden();
});

it('does not change the script when the settings are saved', function () {
    $app = scriptApp(Stack::Node, ['release_script' => "npm run migrate\n", 'container_port' => 3000, 'source_type' => 'upload']);

    $this->put('/apps/shop', ['container_port' => 3001, 'health_path' => '/', 'health_timeout' => '', 'name' => 'Shop'])->assertSessionHasNoErrors();

    expect($app->fresh()->release_script)->toBe("npm run migrate\n");
});
