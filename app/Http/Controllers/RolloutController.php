<?php

namespace App\Http\Controllers;

use App\Deploy\Safe\RolloutService;
use App\Models\App;
use App\Models\Rollout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What a person can do to a gradual rollout that is under way: give the new release the next
 * share, give it everyone now, or abort it.
 */
class RolloutController extends Controller
{
    public function advance(Request $request, App $app, RolloutService $rollouts): RedirectResponse
    {
        $rollout = $this->active($app);
        $rollouts->advance($rollout, $request->user());

        return back()->with('status', 'The rollout moved on.');
    }

    public function promote(Request $request, App $app, RolloutService $rollouts): RedirectResponse
    {
        $rollout = $this->active($app);
        $rollouts->complete($rollout, 'Everyone was given the new version by '.$request->user()?->name.'.', $request->user());

        return back()->with('status', 'Everyone has the new version now, and the old container was stopped.');
    }

    public function abort(Request $request, App $app, RolloutService $rollouts): RedirectResponse
    {
        $rollout = $this->active($app);
        $rollouts->abort($rollout, $request->user(), 'Aborted.');

        return back()->with('status', 'The rollout was aborted. All traffic is on the previous version.');
    }

    private function active(App $app): Rollout
    {
        return Rollout::activeFor($app) ?? abort(404);
    }
}
