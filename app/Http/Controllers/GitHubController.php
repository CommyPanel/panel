<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Dns\PanelDomain;
use App\GitHub\GitHubApp;
use App\GitHub\GitHubFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connects the panel to GitHub with a GitHub App it creates for itself (the manifest flow):
 * the person confirms on github.com, GitHub sends a one-time code back, and the panel trades
 * it for the app's key and webhook secret. The `state` ties the return to this person's
 * session and works once, within ten minutes.
 */
class GitHubController extends Controller
{
    private const PENDING = 'github.pending';

    private const WINDOW = 600;

    public function index(Request $request, GitHubApp $github, PanelDomain $panel): Response
    {
        $ready = $panel->name() !== null && $panel->certificateUsable();
        $pending = $this->pending($request);
        $repos = [];
        $error = null;

        if ($github->installed()) {
            try {
                $repos = $github->repositories();
            } catch (GitHubFailed $e) {
                $error = $e->getMessage();
            }
        }

        return Inertia::render('settings/github', [
            'github' => $github->toArray(),
            'panel_ready' => $ready,
            'panel_name' => $panel->name(),
            'repositories' => array_map(fn (array $r) => ['name' => $r['name'], 'private' => $r['private']], $repos),
            'error' => $error,
            'start' => $pending !== null && $ready && ! $github->connected() ? [
                'action' => $pending['org'] === null
                    ? __('https://github.com/settings/apps/new')
                    : "https://github.com/organizations/{$pending['org']}/settings/apps/new",
                'state' => $pending['state'],
                'manifest' => json_encode($github->manifest('https://'.$panel->name()), JSON_UNESCAPED_SLASHES),
            ] : null,
        ]);
    }

    /**
     * The person chose where to create the app. Nothing is sent to GitHub yet: the next page
     * holds the form that the person submits to github.com themselves.
     */
    public function prepare(Request $request, GitHubApp $github, PanelDomain $panel): RedirectResponse
    {
        $data = $request->validate([
            'account' => ['required', 'in:personal,organization'],
            'organization' => ['required_if:account,organization', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/'],
        ]);

        if ($github->connected()) {
            return back()->withErrors(['github' => __('GitHub is already connected. Disconnect it first.')]);
        }

        if ($panel->name() === null || ! $panel->certificateUsable()) {
            return back()->withErrors(['github' => __('GitHub needs to reach the panel over https. Set the panel\'s domain and wait for its certificate first.')]);
        }

        $request->session()->put(self::PENDING, [
            'state' => bin2hex(random_bytes(24)),
            'org' => $data['account'] === 'organization' ? $data['organization'] : null,
            'at' => time(),
        ]);

        return redirect()->route('settings.github');
    }

    /**
     * GitHub sends the person back here with a one-time code.
     */
    public function callback(Request $request, GitHubApp $github, AuditLogger $audit): RedirectResponse
    {
        $pending = $this->pending($request);
        $request->session()->forget(self::PENDING);

        $state = $request->query('state');
        $code = $request->query('code');

        if ($pending === null || ! is_string($state) || ! is_string($code) || ! hash_equals($pending['state'], $state) || $github->connected()) {
            return redirect()->route('settings.github')->withErrors(['github' => __('That return from GitHub was not expected, so it was ignored. Start again.')]);
        }

        try {
            $github->convert($code);
        } catch (GitHubFailed $e) {
            return redirect()->route('settings.github')->withErrors(['github' => $e->getMessage()]);
        }

        $audit->record('github.connected', $request->user(), ['app' => $github->toArray()['slug']]);

        // Next: install the app on an account, which only the person can do on github.com.
        return redirect()->route('settings.github')->with('status', __('The app was created. Now install it and choose which repositories it may read.'));
    }

    /**
     * GitHub sends the person back here after installing. Nothing in the request is used: the
     * installation is looked up with the app's own key.
     */
    public function installed(GitHubApp $github, AuditLogger $audit, Request $request): RedirectResponse
    {
        return $this->sync($github, $audit, $request);
    }

    public function refresh(GitHubApp $github, AuditLogger $audit, Request $request): RedirectResponse
    {
        return $this->sync($github, $audit, $request);
    }

    public function destroy(Request $request, GitHubApp $github, AuditLogger $audit): RedirectResponse
    {
        $slug = $github->toArray()['slug'];
        $github->disconnect();
        $audit->record('github.disconnected', $request->user(), ['app' => $slug]);

        return redirect()->route('settings.github')->with('status', __('Disconnected. Apps that were set up through GitHub cannot fetch their code until you connect again. Delete the app on github.com too, if you no longer want it there.'));
    }

    private function sync(GitHubApp $github, AuditLogger $audit, Request $request): RedirectResponse
    {
        if (! $github->connected()) {
            return redirect()->route('settings.github');
        }

        try {
            $github->syncInstallation();
        } catch (GitHubFailed $e) {
            return redirect()->route('settings.github')->withErrors(['github' => $e->getMessage()]);
        }

        $audit->record('github.installation_synced', $request->user(), ['installed' => $github->installed(), 'account' => $github->toArray()['account']]);

        return redirect()->route('settings.github')->with('status', $github->installed()
            ? __('Connected. New apps can now pick a repository from GitHub.')
            : __('The app is not installed on an account yet.'));
    }

    /**
     * @return array{state: string, org: string|null, at: int}|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || ! is_string($pending['state'] ?? null) || ! is_int($pending['at'] ?? null) || time() - $pending['at'] > self::WINDOW) {
            return null;
        }

        return ['state' => $pending['state'], 'org' => is_string($pending['org'] ?? null) ? $pending['org'] : null, 'at' => $pending['at']];
    }
}
