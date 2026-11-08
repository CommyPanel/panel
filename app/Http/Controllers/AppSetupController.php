<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Auth\Permissions;
use App\Databases\DatabaseException;
use App\Databases\DatabaseServers;
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
use App\Enums\DatabaseEngine;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\FastApps\FastApps;
use App\FastApps\QuickInstall;
use App\GitHub\GitHubFailed;
use App\Http\Requests\FinishSetupRequest;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\DatabaseServer;
use App\Presenters\AppPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            'quick' => $app->source_type === SourceType::Template ? $this->quick($app) : null,
            'server' => $server->forPage(),
            'limits' => ['max_archive_mb' => (int) config('commypanel.uploads.max_archive_mb')],
        ]);
    }

    /**
     * What the Database step of a quick-install app shows: the servers that work with it, which one is
     * chosen, and whether it can be used yet.
     *
     * @return array<string, mixed>|null
     */
    private function quick(App $app): ?array
    {
        $template = FastApps::find($app->template);

        if ($template === null) {
            return null;
        }

        $quick = app(QuickInstall::class);

        return [
            'template' => ['id' => $template->id(), 'name' => $template->name(), 'admin_path' => $template->adminPath()],
            'engines' => collect($template->engines())->map(fn (DatabaseEngine $e) => ['value' => $e->value, 'label' => $e->label(), 'versions' => $e->versions()])->all(),
            'servers' => collect($quick->servers($template))->map(fn (DatabaseServer $s) => [
                'id' => $s->id,
                'slug' => $s->slug,
                'name' => $s->name,
                'engine_label' => $s->engine->label(),
                'version' => $s->version,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'error' => $s->error,
            ])->all(),
            'selected' => $app->template_database_server_id,
            'ready' => $quick->serverReady($app),
            'database_name' => $app->template_database_server_id === null ? null : $this->previewName($template->id(), $app->slug),
            'can_create_server' => (bool) request()->user()?->can(Permissions::DATABASES_MANAGE),
        ];
    }

    private function previewName(string $id, string $slug): string
    {
        return rtrim(substr($id.'_'.str_replace('-', '_', $slug), 0, 26), '_');
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

        return back()->with('status', __('Project uploaded (:count files).', ['count' => $count]));
    }

    public function continue(App $app): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Source, 404);

        if ($app->source_verified_at === null) {
            return back()->withErrors(['source' => $app->source_type === SourceType::Git
                ? __('Verify access to the repository first.')
                : __('Upload your project first.')]);
        }

        $app->forceFill(['setup_step' => SetupStep::Settings])->save();

        return redirect()->route('apps.setup', $app);
    }

    public function back(App $app): RedirectResponse
    {
        $previous = match ($app->setup_step) {
            SetupStep::Settings => SetupStep::Source,
            SetupStep::Domain => $app->source_type === SourceType::Template ? SetupStep::Database : SetupStep::Settings,
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

    /**
     * The Database step of a quick-install app: choose the server its database will be made on.
     */
    public function database(Request $request, App $app, QuickInstall $quick): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Database && $app->source_type === SourceType::Template, 404);

        $data = $request->validate(['server' => ['required', 'integer', Rule::exists('database_servers', 'id')]]);
        $server = DatabaseServer::query()->whereKey((int) $data['server'])->firstOrFail();

        try {
            $quick->chooseServer($app, $server);

            if (! $server->isRunning()) {
                throw new DatabaseException(__('That database server is still starting. Continue when it is running.'));
            }
        } catch (DatabaseException $e) {
            return back()->withErrors(['server' => $e->getMessage()]);
        }

        $app->forceFill(['setup_step' => SetupStep::Domain])->save();

        return redirect()->route('apps.setup', $app);
    }

    /**
     * Make a new database server from inside the wizard, for when none fits. It starts in the
     * background; the page follows it and the new server is chosen for the app.
     */
    public function createServer(Request $request, App $app, DatabaseServers $servers, QuickInstall $quick, AuditLogger $audit): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Database && $app->source_type === SourceType::Template, 404);
        abort_unless($request->user()->can(Permissions::DATABASES_MANAGE), 403);

        $template = FastApps::find($app->template);
        abort_if($template === null, 404);

        $engines = array_map(fn (DatabaseEngine $e) => $e->value, $template->engines());
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'engine' => ['required', Rule::in($engines)],
            'version' => ['required', 'string', 'max:16'],
        ]);

        try {
            $server = $servers->create($data['name'], DatabaseEngine::from($data['engine']), $data['version']);
            $quick->chooseServer($app, $server);
        } catch (DatabaseException $e) {
            return back()->withErrors(['version' => $e->getMessage()]);
        }

        $audit->record('database_server.created', $request->user(), ['server' => $server->slug, 'engine' => $server->engine->value, 'version' => $server->version, 'for_app' => $app->slug]);

        return back()->with('status', __(':name is being set up. The first start downloads the image and can take a few minutes.', ['name' => $server->name]));
    }

    public function finish(Request $request, App $app, Deployer $deployer, AuditLogger $audit, QuickInstall $quick): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Domain && $app->source_verified_at !== null, 404);

        if ($app->source_type === SourceType::Template) {
            try {
                $quick->finish($app, $request->user());
            } catch (DatabaseException $e) {
                return back()->withErrors(['setup' => $e->getMessage()]);
            }
        }

        $app->forceFill(['setup_step' => null])->save();

        $audit->record('app.created', null, ['app' => $app->slug, 'domains' => $app->domains()->count()], $app);
        $deployment = $deployer->deploy($app->refresh(), $request->user());

        return redirect()->route('apps.deployments.show', [$app, $deployment->number])
            ->with('status', __('App created. The first deployment is starting.'));
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

        return redirect()->route('apps.index')->with('status', __('Setup cancelled.'));
    }
}
