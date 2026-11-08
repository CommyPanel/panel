<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\DeployKeyGenerator;
use App\Deploy\DeploymentFailed;
use App\Models\App;
use Illuminate\Http\RedirectResponse;

class DeployKeyController extends Controller
{
    public function store(App $app, DeployKeyGenerator $generator, AuditLogger $audit): RedirectResponse
    {
        try {
            $key = $generator->generate("commypanel-{$app->slug}");
        } catch (DeploymentFailed $e) {
            return back()->withErrors(['deploy_key' => $e->getMessage()]);
        }

        $app->forceFill(['deploy_key_private' => $key['private'], 'deploy_key_public' => $key['public']])->save();
        $audit->record('app.deploy_key_generated', null, ['app' => $app->slug], $app);

        return back()->with('status', __('Deploy key created. Add the public key to your repository, then deploy.'));
    }
}
