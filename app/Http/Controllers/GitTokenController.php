<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\Source\GitCredentials;
use App\Deploy\Source\GitProvider;
use App\Deploy\Source\GitUrl;
use App\Deploy\Source\RepositoryAccessChecker;
use App\Enums\SourceType;
use App\Models\App;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * HTTPS access tokens for private repositories, the alternative to a deploy
 * key. A token is tested against the repository before it is saved, stored
 * encrypted and never shown again.
 */
class GitTokenController extends Controller
{
    public function store(Request $request, App $app, RepositoryAccessChecker $checker, AuditLogger $audit): RedirectResponse
    {
        abort_unless($app->source_type === SourceType::Git, 404);

        $defaultUsername = GitProvider::fromUrl((string) $app->git_url)->tokenUsername();
        $data = $request->validate([
            'git_token' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! GitCredentials::isValidToken((string) $value)) {
                    $fail('That does not look like an access token. Paste it without spaces or line breaks.');
                }
            }],
            'git_token_username' => [$defaultUsername === null ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! GitCredentials::isValidUsername((string) $value)) {
                    $fail('Enter only the account name: letters, digits and . _ @ + -');
                }
            }],
        ], ['git_token_username.required' => 'Enter the username the token belongs to.']);

        $username = $data['git_token_username'] ?? $defaultUsername;
        $credentials = GitCredentials::token((string) $username, $data['git_token']);
        $result = $checker->check((string) $app->git_url, $app->git_branch, $credentials);

        if (! $result->state->isReady()) {
            return back()->withErrors(['git_token' => $result->message]);
        }

        $app->forceFill([
            'git_token' => $data['git_token'],
            'git_token_username' => $username,
            'git_url' => $credentials->remote((string) $app->git_url) ?? $app->git_url,
            'source_verified_at' => now(),
        ])->save();

        $audit->record('app.git_token_saved', null, ['app' => $app->slug], $app);

        return back()->with('status', 'Access token saved. This server can read the repository.');
    }

    public function destroy(App $app, AuditLogger $audit): RedirectResponse
    {
        abort_unless($app->source_type === SourceType::Git && $app->git_token !== null, 404);

        $attributes = ['git_token' => null, 'git_token_username' => null];

        // The deploy key takes over, and it only works over SSH.
        if ($app->deploy_key_private !== null) {
            $attributes['git_url'] = GitUrl::toSsh((string) $app->git_url) ?? $app->git_url;
        }

        if ($app->setup_step !== null) {
            $attributes['source_verified_at'] = null;
        }

        $app->forceFill($attributes)->save();
        $audit->record('app.git_token_removed', null, ['app' => $app->slug], $app);

        return back()->with('status', $app->deploy_key_private !== null
            ? 'Access token removed. Deployments use the deploy key again.'
            : 'Access token removed. Deployments only work if the repository is public.');
    }
}
