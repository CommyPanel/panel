<?php

namespace App\Http\Controllers;

use App\Deploy\Deployer;
use App\Deploy\DeploymentFailed;
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
            ->whereIn('status', [DeploymentStatus::Queued, DeploymentStatus::Building, DeploymentStatus::Starting])
            ->exists();
    }
}
