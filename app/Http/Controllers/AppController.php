<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Auth\Permissions;
use App\Deploy\DestroyApp;
use App\Deploy\LockedException;
use App\Deploy\Source\GitProvider;
use App\Dns\PanelDomain;
use App\Dns\ServerAddress;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\GitHub\GitHubApp;
use App\GitHub\GitHubFailed;
use App\Http\Requests\StoreAppRequest;
use App\Http\Requests\UpdateAppRequest;
use App\Models\App;
use App\Presenters\AppPresenter;
use App\Tls\HttpsSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppController extends Controller
{
    public function index(): Response
    {
        $apps = App::query()->with(['activeDeployment', 'latestDeployment'])->orderBy('name')->get();

        return Inertia::render('apps/index', [
            'apps' => $apps->map(fn (App $app) => AppPresenter::summary($app))->values(),
        ]);
    }

    public function create(GitHubApp $github): Response
    {
        $repos = ['repositories' => [], 'error' => null];

        if ($github->installed()) {
            try {
                $repos['repositories'] = $github->repositories();
            } catch (GitHubFailed $e) {
                $repos['error'] = $e->getMessage();
            }
        }

        return Inertia::render('apps/create', [
            'github' => $github->installed() ? $repos : null,
        ]);
    }

    public function store(StoreAppRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $github = $data['source'] === 'github';
        $git = $github || $data['source'] === SourceType::Git->value;

        $app = App::query()->create([
            'name' => $data['name'],
            'slug' => App::generateSlug($data['name']),
            'stack_hint' => $data['stack_hint'] ?? null,
            'source_type' => $git ? SourceType::Git : SourceType::Upload,
            'git_url' => $github ? "https://github.com/{$data['github_repo']}.git" : ($git ? $data['git_url'] : null),
            'github_repo' => $github ? $data['github_repo'] : null,
            'git_branch' => $git ? $data['git_branch'] : 'main',
            'setup_step' => SetupStep::Source,
        ]);

        $audit->record('app.setup_started', null, ['app' => $app->slug], $app);

        return redirect()->route('apps.setup', $app);
    }

    public function show(Request $request, App $app, HttpsSettings $https, ServerAddress $server, PanelDomain $panel): Response|RedirectResponse
    {
        if ($app->setup_step !== null) {
            return redirect()->route('apps.setup', $app);
        }

        $app->load(['activeDeployment', 'latestDeployment', 'domains', 'certificate']);

        return Inertia::render('apps/show', [
            'app' => AppPresenter::settings($app),
            'deployments' => $app->deployments()->latest('number')->limit(15)->get()
                ->map(fn ($deployment) => AppPresenter::deployment($deployment))->values(),
            'env_keys' => $app->envVars()->orderBy('key')->pluck('key')->values(),
            'https' => ['enabled' => $https->enabled(), 'staging' => $https->staging()],
            'server' => $server->forPage(),
            'webhook' => $this->webhook($request, $app, $panel),
        ]);
    }

    /**
     * The push webhook's address and, for people who may change the app, its secret.
     *
     * @return array<string, mixed>|null
     */
    private function webhook(Request $request, App $app, PanelDomain $panel): ?array
    {
        if ($app->source_type !== SourceType::Git) {
            return null;
        }

        // Read through the GitHub App: one switch, no address or secret to copy.
        if ($app->github_repo !== null) {
            return ['github' => true, 'enabled' => $app->github_auto_deploy, 'branch' => $app->git_branch, 'provider' => 'GitHub', 'settings_url' => null, 'steps' => []];
        }

        $provider = GitProvider::fromUrl((string) $app->git_url);
        $help = $provider->webhookInstructions((string) $app->git_url);
        $data = ['enabled' => $app->webhook_id !== null, 'provider' => $provider->label(), 'settings_url' => $help['url'], 'steps' => $help['steps']];

        if ($app->webhook_id === null) {
            return $data;
        }

        $public = $panel->name() !== null && $panel->certificateUsable();
        $base = $public ? 'https://'.$panel->name() : url('/');

        return [
            ...$data,
            'url' => rtrim($base, '/').'/webhooks/deploy/'.$app->webhook_id,
            'secret' => $request->user()?->can(Permissions::APPS_UPDATE) ? $app->webhook_secret : null,
            // Without the panel's own HTTPS domain the address may not be reachable from the Git host.
            'on_panel_domain' => $public,
        ];
    }

    public function update(UpdateAppRequest $request, App $app, AuditLogger $audit): RedirectResponse
    {
        $app->update($request->validated());
        $audit->record('app.updated', null, ['app' => $app->slug], $app);

        return back()->with('status', 'Settings saved. They apply from the next deployment.');
    }

    public function destroy(Request $request, App $app, DestroyApp $destroy, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:'.$app->slug]], [
            'confirm.in' => 'Type the app name exactly to confirm.',
        ]);

        $slug = $app->slug;

        try {
            $problems = $destroy($app);
        } catch (LockedException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        $audit->record('app.deleted', null, ['app' => $slug, 'cleanup_problems' => $problems]);

        $redirect = redirect()->route('apps.index')->with('status', "App {$slug} deleted.");

        return $problems === []
            ? $redirect
            : $redirect->with('warning', 'Some resources could not be removed: '.implode('; ', $problems));
    }
}
