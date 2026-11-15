<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Auth\ActiveSessions;
use App\Auth\UserAccess;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Other people's signed-in browsers. Seeing them needs `sessions.view`; signing them out needs
 * `sessions.revoke` plus the usual rule that nobody touches a user with more access than their
 * own, and never the super user. A person's own browsers are on their Security page.
 */
class UserSessionController extends Controller
{
    public function __construct(private readonly UserAccess $access, private readonly ActiveSessions $sessions) {}

    public function index(Request $request, User $user, AuditLogger $audit): Response
    {
        $actor = $request->user();
        abort_if($user->is($actor), 404);
        abort_if($user->isSuper() && ! $actor->isSuper(), 403);

        $audit->record('user.sessions_viewed', null, ['user' => $user->email], $user);

        return Inertia::render('users/sessions', [
            'target' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'sessions' => ['supported' => $this->sessions->supported(), 'list' => $this->sessions->for($user)],
            'can_revoke' => $this->mayRevoke($request, $user),
        ]);
    }

    public function destroy(Request $request, User $user, string $handle, AuditLogger $audit): RedirectResponse
    {
        $this->guard($request, $user);

        if (! $this->sessions->revoke($user, $handle)) {
            return back()->withErrors(['session' => __('That session is already gone.')]);
        }

        $audit->record('user.session_revoked', null, ['user' => $user->email], $user);

        return back()->with('status', __('That browser was signed out.'));
    }

    public function destroyAll(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $this->guard($request, $user);

        $count = $this->sessions->revokeAll($user);
        $audit->record('user.sessions_revoked', null, ['user' => $user->email, 'count' => $count], $user);

        return back()->with('status', __(':name was signed out everywhere.', ['name' => $user->name]));
    }

    private function mayRevoke(Request $request, User $user): bool
    {
        $actor = $request->user();

        return $actor->can('sessions.revoke') && ! $user->is($actor) && $this->access->canManage($actor, $user);
    }

    private function guard(Request $request, User $user): void
    {
        abort_unless($this->mayRevoke($request, $user), 403);
    }
}
