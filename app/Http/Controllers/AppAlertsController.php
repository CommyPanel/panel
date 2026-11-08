<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\App;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Mutes the alerts about one app (it stopped answering, a deployment failed, ...). Alerts
 * about the server itself are not affected.
 */
class AppAlertsController extends Controller
{
    public function update(Request $request, App $app, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $data['enabled'];

        $app->forceFill(['alerts_enabled' => $enabled])->save();
        $audit->record('app.alerts_changed', $request->user(), ['app' => $app->slug, 'enabled' => $enabled], $app);

        return back()->with('status', $enabled ? __('Alerts about this app are on.') : __('Alerts about this app are muted.'));
    }
}
