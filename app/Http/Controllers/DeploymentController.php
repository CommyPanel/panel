<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\Deployer;
use App\Deploy\DeploymentFailed;
use App\Deploy\Safe\WatchService;
use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;
use App\Presenters\AppPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeploymentController extends Controller
{
    public function store(Request $request, App $app, Deployer $deployer): RedirectResponse
    {
        if ($this->inProgress($app)) {
            return back()->withErrors(['deploy' => 'A deployment is already in progress.']);
        }

        $deployment = $deployer->deploy($app, $request->user());

        return redirect()->route('apps.deployments.show', [$app, $deployment]);
    }

    public function show(App $app, Deployment $deployment): Response
    {
        return Inertia::render('apps/deployment', [
            'app' => ['slug' => $app->slug, 'name' => $app->name],
            'deployment' => AppPresenter::deployment($deployment, withLog: true),
        ]);
    }

    public function rollback(Request $request, App $app, Deployment $deployment, Deployer $deployer): RedirectResponse
    {
        if ($this->inProgress($app)) {
            return back()->withErrors(['deploy' => 'A deployment is already in progress.']);
        }

        try {
            $new = $deployer->rollback($app, $deployment, $request->user());
        } catch (DeploymentFailed $e) {
            return back()->withErrors(['deploy' => $e->getMessage()]);
        }

        return redirect()->route('apps.deployments.show', [$app, $new]);
    }

    /**
     * Try a release that was rolled back automatically once more, on purpose.
     */
    public function retry(Request $request, App $app, Deployment $deployment, Deployer $deployer): RedirectResponse
    {
        $data = $request->validate(['watch' => ['required', 'boolean']]);

        if ($this->inProgress($app)) {
            return back()->withErrors(['deploy' => 'A deployment is already in progress.']);
        }

        try {
            $new = $deployer->retry($app, $deployment, $request->user(), (bool) $data['watch']);
        } catch (DeploymentFailed $e) {
            return back()->withErrors(['deploy' => $e->getMessage()]);
        }

        return redirect()->route('apps.deployments.show', [$app, $new]);
    }

    /**
     * Stop watching a release that went live: accept it and stop the release it replaced.
     */
    public function endWatch(Request $request, App $app, Deployment $deployment, WatchService $watch, AuditLogger $audit): RedirectResponse
    {
        abort_unless($deployment->watch_state === 'watching', 404);

        $watch->finish($app, 'A person ended the watch.');
        $audit->record('deploy.watch_ended', $request->user(), ['app' => $app->slug, 'deployment' => $deployment->number], $app);

        return back()->with('status', 'The watch is over. The previous version was stopped.');
    }

    /**
     * Make changed variables, volumes and links take effect without a build and without downtime.
     */
    public function apply(Request $request, App $app, Deployer $deployer): RedirectResponse
    {
        if ($this->inProgress($app)) {
            return back()->withErrors(['deploy' => 'A deployment is already in progress.']);
        }

        try {
            $new = $deployer->apply($app->load('activeDeployment'), $request->user());
        } catch (DeploymentFailed $e) {
            return back()->withErrors(['deploy' => $e->getMessage()]);
        }

        return redirect()->route('apps.deployments.show', [$app, $new]);
    }

    private function inProgress(App $app): bool
    {
        return $app->deployments()
            ->whereIn('status', DeploymentStatus::working())
            ->exists();
    }
}
