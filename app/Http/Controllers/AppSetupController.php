<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\Deployer;
use App\Deploy\DestroyApp;
use App\Deploy\EnvParser;
use App\Deploy\LockedException;
use App\Deploy\Source\AccessState;
use App\Deploy\Source\GitCredentials;
use App\Deploy\Source\GitProvider;
use App\Deploy\Source\GitUrl;
use App\Deploy\Source\InvalidArchive;
use App\Deploy\Source\RepositoryAccessChecker;
use App\Deploy\Source\UploadStorage;
use App\Dns\ServerAddress;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\GitHub\GitHubFailed;
use App\Http\Requests\FinishSetupRequest;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Presenters\AppPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The steps after the basics: get access to the code, then the settings.
 * The app stays a draft (setup_step set) until the last step.
 */
class AppSetupController extends Controller
{
    public function show(App $app, UploadStorage $uploads, ServerAddress $server): Response|RedirectResponse
    {
        if ($app->setup_step === null) {
            return redirect()->route('apps.show', $app);
        }

        $app->load('domains');
        $git = $app->source_type === SourceType::Git;
        $provider = $git ? GitProvider::fromUrl((string) $app->git_url) : null;

        return Inertia::render('apps/setup', [
            'app' => [
                ...AppPresenter::settings($app),
                'setup_step' => $app->setup_step->value,
                'stack_hint' => $app->stack_hint?->value,
                'source_type' => $app->source_type->value,
                'source_verified' => $app->source_verified_at !== null,
                'has_upload' => ! $git && $uploads->has($app),
                'ssh_url' => $git ? GitUrl::toSsh((string) $app->git_url) : null,
            ],
            'provider' => $provider === null ? null : [
                'name' => $provider->label(),
                ...($app->deploy_key_public === null ? ['url' => null, 'steps' => []] : $provider->deployKeyInstructions((string) $app->git_url)),
            ],
            'server' => $server->forPage(),
            'limits' => ['max_archive_mb' => (int) config('commypanel.uploads.max_archive_mb')],
        ]);
    }

    /**
     * Test whether the server can read the repository, with the deploy key if there is one.
     */
    public function check(Request $request, App $app, RepositoryAccessChecker $checker): JsonResponse
    {
        abort_unless($app->setup_step !== null && $app->source_type === SourceType::Git, 404);

        $data = $request->validate(['git_branch' => ['sometimes', 'string', 'max:200']]);

        if (isset($data['git_branch']) && $data['git_branch'] !== $app->git_branch) {
            $app->forceFill(['git_branch' => $data['git_branch'], 'source_verified_at' => null])->save();
        }

        try {
            $credentials = GitCredentials::forApp($app);
        } catch (GitHubFailed $e) {
            return response()->json(['state' => AccessState::Unreachable->value, 'message' => $e->getMessage(), 'ready' => false, 'branches' => []]);
        }

        $result = $checker->check((string) $app->git_url, $app->git_branch, $credentials);

        // Deployments should use the address the working credentials need.
        if ($credentials !== null && $result->state->isReady()) {
            $app->git_url = $credentials->remote((string) $app->git_url) ?? $app->git_url;
        }

        $app->forceFill(['source_verified_at' => $result->state->isReady() ? now() : null])->save();

        return response()->json([
            'state' => $result->state->value,
            'message' => $result->message,
            'ready' => $result->state->isReady(),
            'branches' => $result->branches,
        ]);
    }

    public function upload(Request $request, App $app, UploadStorage $uploads, AuditLogger $audit): RedirectResponse
    {
        abort_unless($app->setup_step !== null && $app->source_type === SourceType::Upload, 404);

        $request->validate([
            'archive' => ['required', 'file', 'max:'.((int) config('commypanel.uploads.max_archive_mb') * 1024)],
        ], ['archive.max' => 'That file is too large.']);

        try {
            $count = $uploads->store($app, $request->file('archive'));
        } catch (InvalidArchive $e) {
            return back()->withErrors(['archive' => $e->getMessage()]);
        }

        $app->forceFill(['source_verified_at' => now()])->save();
        $audit->record('app.source_uploaded', null, ['app' => $app->slug, 'files' => $count], $app);

        return back()->with('status', "Project uploaded ({$count} files).");
    }

    public function continue(App $app): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Source, 404);

        if ($app->source_verified_at === null) {
            return back()->withErrors(['source' => $app->source_type === SourceType::Git
                ? 'Verify access to the repository first.'
                : 'Upload your project first.']);
        }

        $app->forceFill(['setup_step' => SetupStep::Settings])->save();

        return redirect()->route('apps.setup', $app);
    }

    public function back(App $app): RedirectResponse
    {
        $previous = match ($app->setup_step) {
            SetupStep::Settings => SetupStep::Source,
            SetupStep::Domain => SetupStep::Settings,
            default => abort(404),
        };

        $app->forceFill(['setup_step' => $previous])->save();

        return redirect()->route('apps.setup', $app);
    }

    public function settings(FinishSetupRequest $request, App $app, EnvParser $parser): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Settings && $app->source_verified_at !== null, 404);

        $data = $request->validated();
        $env = $parser->parse((string) ($data['env'] ?? ''));
        unset($data['env']);

        DB::transaction(function () use ($app, $data, $env) {
            $app->forceFill([...$data, 'setup_step' => SetupStep::Domain])->save();

            foreach ($env['vars'] as $key => $value) {
                AppEnvVar::query()->updateOrCreate(['app_id' => $app->id, 'key' => $key], ['value' => $value]);
            }
        });

        return redirect()->route('apps.setup', $app);
    }

    public function finish(Request $request, App $app, Deployer $deployer, AuditLogger $audit): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Domain && $app->source_verified_at !== null, 404);

        $app->forceFill(['setup_step' => null])->save();

        $audit->record('app.created', null, ['app' => $app->slug, 'domains' => $app->domains()->count()], $app);
        $deployment = $deployer->deploy($app->refresh(), $request->user());

        return redirect()->route('apps.deployments.show', [$app, $deployment->number])
            ->with('status', 'App created. The first deployment is starting.');
    }

    public function cancel(App $app, DestroyApp $destroy, AuditLogger $audit): RedirectResponse
    {
        abort_unless($app->setup_step !== null, 404);

        $slug = $app->slug;

        try {
            $destroy($app);
        } catch (LockedException $e) {
            return back()->withErrors(['setup' => $e->getMessage()]);
        }

        $audit->record('app.setup_cancelled', null, ['app' => $slug]);

        return redirect()->route('apps.index')->with('status', 'Setup cancelled.');
    }
}
