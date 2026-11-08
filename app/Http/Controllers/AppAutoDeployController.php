<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\App;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Turns "deploy on every push" on or off for an app that reads its code through the GitHub App.
 * Off by default; the page asks for confirmation either way.
 */
class AppAutoDeployController extends Controller
{
    public function update(Request $request, App $app, AuditLogger $audit): RedirectResponse
    {
        abort_unless($app->github_repo !== null && $app->setup_step === null, 404);

        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $app->forceFill(['github_auto_deploy' => $enabled])->save();

        $audit->record($enabled ? 'app.auto_deploy_enabled' : 'app.auto_deploy_disabled', $request->user(), ['app' => $app->slug, 'branch' => $app->git_branch], $app);

        return back()->with('status', $enabled
            ? __('Deploy on push is on. A push to :git_branch now deploys this app.', ['git_branch' => $app->git_branch])
            : __('Deploy on push is off. Pushes no longer deploy this app.'));
    }
}
