<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Updates\UpdateChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Whether a newer CommyPanel exists, what changed, and how to upgrade. The
 * page only reads what is remembered; "Check now" asks GitHub on request.
 */
class UpdateController extends Controller
{
    public function show(UpdateChecker $updates): Response
    {
        return Inertia::render('settings/updates', ['update' => $updates->status()->page()]);
    }

    public function check(UpdateChecker $updates, AuditLogger $audit): RedirectResponse
    {
        if ($updates->forcedOff()) {
            return back()->withErrors(['update' => __('Update checks are switched off in the server configuration (COMMYPANEL_UPDATE_CHECK).')]);
        }

        if (! $updates->supported()) {
            return back()->withErrors(['update' => __('This is a development build, so there is no version to compare.')]);
        }

        $status = $updates->check();
        $audit->record('updates.checked', null, ['available' => $status->available, 'latest' => $status->latest, 'failed' => $status->error !== null]);

        if ($status->error !== null) {
            return back()->withErrors(['update' => $status->error]);
        }

        return back()->with('status', $status->available
            ? __('Version :latest is available.', ['latest' => $status->latest])
            : __('You are running the latest version.'));
    }

    public function update(Request $request, UpdateChecker $updates, AuditLogger $audit): RedirectResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        if ($updates->forcedOff()) {
            return back()->withErrors(['update' => __('Update checks are switched off in the server configuration (COMMYPANEL_UPDATE_CHECK).')]);
        }

        $updates->setEnabled((bool) $enabled);
        $audit->record($enabled ? 'updates.enabled' : 'updates.disabled');

        return back()->with('status', $enabled
            ? __('CommyPanel will look for new versions when someone signs in.')
            : __('Automatic update checks are off. You can still check by hand.'));
    }
}
