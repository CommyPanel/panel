<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Auth\ActiveSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A person's own signed-in browsers: sign one out, or all the others. */
class OwnSessionController extends Controller
{
    public function destroy(Request $request, string $handle, ActiveSessions $sessions, AuditLogger $audit): RedirectResponse
    {
        if (! $sessions->revoke($request->user(), $handle, $request->session()->getId())) {
            return back()->withErrors(['session' => __('That session is gone or is the one you are using. Use Sign out for this one.')]);
        }

        $audit->record('auth.session_revoked', null, ['own' => true]);

        return back()->with('status', __('That browser was signed out.'));
    }

    public function destroyOthers(Request $request, ActiveSessions $sessions, AuditLogger $audit): RedirectResponse
    {
        $count = $sessions->revokeAll($request->user(), $request->session()->getId());
        $audit->record('auth.other_sessions_revoked', null, ['count' => $count]);

        return back()->with('status', __('Every other browser was signed out.'));
    }
}
