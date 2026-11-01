<?php

namespace App\Http\Controllers;

use App\Deploy\ControlApp;
use App\Deploy\DeactivateApp;
use App\Deploy\DeploymentFailed;
use App\Models\App;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AppStateController extends Controller
{
    public function deactivate(Request $request, App $app, DeactivateApp $action): RedirectResponse
    {
        if ($app->isDeploying()) {
            return back()->withErrors(['state' => 'Wait for the running deployment to finish first.']);
        }

        try {
            $action->deactivate($app, $request->user());
        } catch (DeploymentFailed $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }

        return back()->with('status', 'The app is deactivated. Its domains no longer serve it.');
    }

    public function activate(Request $request, App $app, DeactivateApp $action): RedirectResponse
    {
        if ($app->active_deployment_id !== null || $app->isDeploying()) {
            return back()->withErrors(['state' => 'The app is already running or starting.']);
        }

        try {
            $deployment = $action->activate($app, $request->user());
        } catch (DeploymentFailed $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }

        return redirect()->route('apps.deployments.show', [$app, $deployment]);
    }

    public function stop(Request $request, App $app, ControlApp $control): RedirectResponse
    {
        return $this->control(fn () => $control->stop($app, $request->user()), 'The app is stopped. Its domains show an error until you start it again.');
    }

    public function start(Request $request, App $app, ControlApp $control): RedirectResponse
    {
        return $this->control(fn () => $control->start($app, $request->user()), 'The app is running again.');
    }

    public function restart(Request $request, App $app, ControlApp $control): RedirectResponse
    {
        return $this->control(fn () => $control->restart($app, $request->user()), 'The app was restarted.');
    }

    /**
     * @param  callable(): void  $action
     */
    private function control(callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (DeploymentFailed $e) {
            return back()->withErrors(['state' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }
}
