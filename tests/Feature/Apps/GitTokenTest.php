<?php

use App\Models\App;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

const TOKEN = 'github_pat_0123456789abcdef';

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function privateRepoApp(array $attributes = []): App
{
    return App::factory()->create(['slug' => 'shop', 'git_url' => 'git@github.com:acme/shop.git', 'git_branch' => 'main', ...$attributes]);
}

it('tests the token, then saves it encrypted and switches to the HTTPS address', function () {
    Process::fake(['*ls-remote*' => Process::result("abc\trefs/heads/main\n")]);
    $app = privateRepoApp();

    $this->post('/apps/shop/git-token', ['git_token' => TOKEN])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $app->refresh();
    expect($app->git_token)->toBe(TOKEN)
        ->and($app->git_token_username)->toBe('x-access-token')
        ->and($app->git_url)->toBe('https://github.com/acme/shop.git')
        ->and($app->source_verified_at)->not->toBeNull()
        ->and(DB::table('apps')->value('git_token'))->not->toContain(TOKEN);

    Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), 'https://github.com/acme/shop.git')
        && ! str_contains(implode(' ', $process->command), TOKEN));

    $audit = AuditLog::query()->where('action', 'app.git_token_saved')->firstOrFail();
    expect(json_encode($audit->toArray()))->not->toContain(TOKEN);
});

it('does not save a token the Git host refuses', function () {
    Process::fake(['*ls-remote*' => Process::result('', "remote: Invalid username or password.\nfatal: Authentication failed for 'https://github.com/acme/shop.git/'", 128)]);
    $app = privateRepoApp();

    $this->post('/apps/shop/git-token', ['git_token' => TOKEN])
        ->assertSessionHasErrors(['git_token' => 'The access token was not accepted. Check that it can read this repository, that the username fits it, and that it has not expired.']);

    expect($app->fresh())->git_token->toBeNull()->git_url->toBe('git@github.com:acme/shop.git');
});

it('validates the token and asks for a username where the host has no default', function () {
    Process::fake();
    privateRepoApp(['git_url' => 'https://git.example.com/team/shop.git']);

    $this->post('/apps/shop/git-token', ['git_token' => 'has spaces in it'])->assertSessionHasErrors(['git_token', 'git_token_username']);
    $this->post('/apps/shop/git-token', ['git_token' => TOKEN, 'git_token_username' => 'me:oops'])->assertSessionHasErrors('git_token_username');

    Process::assertNothingRan();
});

it('uses the username the user gives for other Git servers', function () {
    Process::fake(['*ls-remote*' => Process::result("abc\trefs/heads/main\n")]);
    $app = privateRepoApp(['git_url' => 'https://git.example.com/team/shop.git']);

    $this->post('/apps/shop/git-token', ['git_token' => TOKEN, 'git_token_username' => 'deploy-bot'])->assertSessionHasNoErrors();

    expect($app->fresh()->git_token_username)->toBe('deploy-bot');
});

it('shows that a token is saved but never the token itself', function () {
    privateRepoApp(['git_token' => TOKEN, 'git_token_username' => 'x-access-token']);

    $response = $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page
        ->where('app.git_token.username', 'x-access-token')
        ->where('app.token_help.username', 'x-access-token')
        ->where('app.token_help.provider', 'GitHub'));

    expect($response->getContent())->not->toContain(TOKEN);
});

it('removes the token and goes back to the deploy key over SSH', function () {
    $app = privateRepoApp([
        'git_url' => 'https://github.com/acme/shop.git', 'git_token' => TOKEN, 'git_token_username' => 'x-access-token',
        'deploy_key_private' => 'KEY', 'deploy_key_public' => 'ssh-ed25519 AAAA',
    ]);

    $this->delete('/apps/shop/git-token')->assertSessionHasNoErrors();

    expect($app->fresh())
        ->git_token->toBeNull()
        ->git_token_username->toBeNull()
        ->git_url->toBe('git@github.com:acme/shop.git')
        ->and(AuditLog::query()->where('action', 'app.git_token_removed')->exists())->toBeTrue();
});

it('makes a draft verify its source again after the token is removed', function () {
    $app = privateRepoApp(['setup_step' => 'source', 'source_verified_at' => now(), 'git_token' => TOKEN, 'git_token_username' => 'x-access-token']);

    $this->delete('/apps/shop/git-token');

    expect($app->fresh()->source_verified_at)->toBeNull();
});

it('only applies to Git apps', function () {
    privateRepoApp(['source_type' => 'upload', 'git_url' => null]);

    $this->post('/apps/shop/git-token', ['git_token' => TOKEN])->assertNotFound();
    $this->delete('/apps/shop/git-token')->assertNotFound();
});
