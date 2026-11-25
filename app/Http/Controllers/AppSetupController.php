<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Databases\AppLinks;
use App\Databases\SetupAppDatabase;
use App\Deploy\AppProcesses;
use App\Deploy\AppVolumes;
use App\Deploy\Deployer;
use App\Deploy\DestroyApp;
use App\Deploy\EnvLimits;
use App\Deploy\EnvParser;
use App\Deploy\LaravelEnvTemplate;
use App\Deploy\LockedException;
use App\Deploy\ReleaseScripts;
use App\Deploy\Source\AccessState;
use App\Deploy\Source\GitCredentials;
use App\Deploy\Source\GitProvider;
use App\Deploy\Source\GitUrl;
use App\Deploy\Source\InvalidArchive;
use App\Deploy\Source\RepositoryAccessChecker;
use App\Deploy\Source\UploadStorage;
use App\Deploy\Stacks\Laravel\InspectSource;
use App\Dns\FreeDomains;
use App\Dns\ServerAddress;
use App\Enums\ProcessKind;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\Enums\Stack;
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
    public function show(App $app, UploadStorage $uploads, ServerAddress $server, FreeDomains $free): Response|RedirectResponse
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
            'free_domains' => $free->names(),
            'limits' => ['max_archive_mb' => (int) config('commypanel.uploads.max_archive_mb')],
            'laravel' => AppProcesses::isLaravel($app) ? $this->laravel($app) : null,
        ]);
    }

    /**
     * What the Laravel steps need: what the code showed (null while it is still being looked at), the
     * choices so far, and, for the last look, a summary.
     *
     * @return array<string, mixed>
     */
    private function laravel(App $app): array
    {
        $detected = $app->detected;
        $review = null;

        if ($app->setup_step === SetupStep::Review) {
            $processes = $app->processes()->where('enabled', true)->orderBy('sort')->get();
            $review = [
                'database' => app(SetupAppDatabase::class)->status($app),
                'processes' => $processes->map(fn ($p) => ['name' => $p->name, 'kind' => $p->kind->value, 'replicas' => $p->replicas])->values()->all(),
                'volumes' => $app->volumes()->orderBy('id')->pluck('path')->all(),
                'domains' => $app->domains()->orderBy('name')->pluck('name')->all(),
                // For the variables editor: how many there are and which names a link provides (never values).
                'env_count' => AppEnvVar::query()->where('app_id', $app->id)->count(),
                'linked_keys' => array_keys(app(AppLinks::class)->environment($app)),
            ];
        }

        return [
            // Null while the inspection job has not answered yet.
            'detected' => $detected,
            'inspecting' => $detected === null,
            'stack_options' => $app->stack_options === null ? (object) [] : $this->publicOptions($app->stack_options),
            'review' => $review,
        ];
    }

    /**
     * The choices a person made, without the bookkeeping other steps keep in the same column.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function publicOptions(array $options): array
    {
        return array_intersect_key($options, array_flip(['php', 'node_manager', 'node', 'ssr']));
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

        // Look at the code (a Laravel app gets its own steps); the settings page follows the answer.
        InspectSource::queue($app);

        return redirect()->route('apps.setup', $app);
    }

    public function back(App $app): RedirectResponse
    {
        // A quick-install app has no earlier step: its choices were made on the first page.
        abort_if($app->source_type === SourceType::Template, 404);

        $previous = match ($app->setup_step) {
            SetupStep::Settings => SetupStep::Source,
            SetupStep::Database => SetupStep::Settings,
            SetupStep::Services => SetupStep::Database,
            SetupStep::Volumes => SetupStep::Services,
            SetupStep::Domain => AppProcesses::isLaravel($app) ? SetupStep::Volumes : SetupStep::Settings,
            SetupStep::Review => SetupStep::Domain,
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
        $options = $data['stack_options'] ?? null;
        unset($data['env'], $data['stack_options']);

        EnvLimits::assertFits($app, $env['vars'], 'env');

        $laravel = AppProcesses::isLaravel($app);

        DB::transaction(function () use ($app, $data, $env, $options, $laravel) {
            // The Laravel choices keep what other steps stored beside them (the database step's progress).
            $stackOptions = $laravel && is_array($options) ? [...($app->stack_options ?? []), ...array_filter($options, fn ($v) => $v !== null && $v !== '')] : $app->stack_options;

            $app->forceFill([...$data, 'stack_options' => $stackOptions, 'setup_step' => $laravel ? SetupStep::Database : SetupStep::Domain])->save();

            foreach ($env['vars'] as $key => $value) {
                AppEnvVar::query()->updateOrCreate(['app_id' => $app->id, 'key' => $key], ['value' => $value]);
            }
        });

        return redirect()->route('apps.setup', $app);
    }

    /** The database step is done: a database is linked, or none was wanted. */
    public function databaseContinue(App $app, SetupAppDatabase $database): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Database && AppProcesses::isLaravel($app), 404);

        $state = $database->status($app);

        $done = $state['mode'] === 'none' || ($state['mode'] !== null && ! in_array($state['status'], ['pending', 'failed'], true));

        if (! $done) {
            return back()->withErrors(['setup' => $state['status'] === 'pending'
                ? __('The database server is still starting. Wait until it is running.')
                : __('Choose a database first, or choose not to use one.')]);
        }

        // The services a Laravel app may run: the scheduler on, what the code showed off.
        app(AppProcesses::class)->seedFor($app, $app->detected['services'] ?? []);
        $app->forceFill(['setup_step' => SetupStep::Services])->save();

        return redirect()->route('apps.setup', $app);
    }

    public function servicesContinue(App $app, AppVolumes $volumes): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Services && AppProcesses::isLaravel($app), 404);

        // The folder Laravel writes to, kept between releases; the person may remove or change it.
        $options = $app->stack_options ?? [];

        if (! ($options['volumes_seeded'] ?? false)) {
            foreach ($app->detected['suggested_volumes'] ?? [['path' => '/app/storage']] as $suggestion) {
                try {
                    $volumes->add($app, (string) $suggestion['path']);
                } catch (\InvalidArgumentException) {
                    // A suggestion that cannot be used is skipped; the person can add their own.
                }
            }

            $app->forceFill(['stack_options' => [...$options, 'volumes_seeded' => true]]);
        }

        $app->forceFill(['setup_step' => SetupStep::Volumes])->save();

        return redirect()->route('apps.setup', $app);
    }

    public function volumesContinue(App $app): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Volumes && AppProcesses::isLaravel($app), 404);

        $app->forceFill(['setup_step' => SetupStep::Domain])->save();

        return redirect()->route('apps.setup', $app);
    }

    /** A Laravel app takes one more look (its variables and release script) before it is built. */
    public function domainContinue(App $app, LaravelEnvTemplate $env): RedirectResponse
    {
        abort_unless($app->setup_step === SetupStep::Domain && AppProcesses::isLaravel($app), 404);

        $enabled = $app->processes()->where('enabled', true)->pluck('kind')->map(fn ($kind) => $kind instanceof ProcessKind ? $kind->value : (string) $kind)->all();

        // A starting set of variables, once; a person's own changes are never replaced.
        if ($app->env_text === null) {
            $domain = $app->domains()->orderBy('name')->value('name');
            $domain = is_string($domain) && ! str_starts_with($domain, '*.') ? $domain : null;
            $horizon = in_array(ProcessKind::Horizon->value, $enabled, true);
            $queue = $horizon || in_array(ProcessKind::Queue->value, $enabled, true);

            $env->apply($app, $env->generate($app, $domain, $queue, $horizon));
        }

        $app->forceFill([
            'release_script' => $app->release_script ?? ReleaseScripts::defaultFor(Stack::Laravel),
            // nginx refuses uploads over 1 MB unless told otherwise, which a Laravel app soon hits.
            'nginx_custom' => $app->nginx_custom ?? "client_max_body_size 100m;\n",
            'setup_step' => SetupStep::Review,
        ])->save();

        return redirect()->route('apps.setup', $app);
    }

    public function finish(Request $request, App $app, Deployer $deployer, AuditLogger $audit): RedirectResponse
    {
        $laravel = AppProcesses::isLaravel($app);

        abort_unless($app->setup_step === ($laravel ? SetupStep::Review : SetupStep::Domain) && $app->source_verified_at !== null, 404);

        if ($laravel) {
            $data = $request->validate(['release_script' => ['nullable', 'string', 'max:'.ReleaseScripts::MAX_LENGTH]]);

            if (array_key_exists('release_script', $data)) {
                $app->forceFill(['release_script' => ReleaseScripts::normalize($data['release_script'])]);
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
