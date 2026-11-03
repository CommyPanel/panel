<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Dns\ConfigurePanelDomain;
use App\Dns\PanelDomain;
use App\Dns\ServerAddress;
use App\Tls\HttpsSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The steps after the admin exists: Let's Encrypt terms, then the panel's own
 * domain. Every step can be skipped and revisited later.
 */
class WelcomeController extends Controller
{
    public function show(HttpsSettings $https, PanelDomain $panel, ServerAddress $server): Response
    {
        $name = $panel->name();

        return Inertia::render('welcome', [
            'https' => ['enabled' => $https->enabled(), 'email' => $https->email(), 'staging' => $https->staging()],
            'server' => $server->forPage(),
            'panel' => [
                'domain' => $name,
                'dns' => $panel->dns(),
                'certificate' => [
                    'status' => $panel->certificateStatus(),
                    'expires_at' => $panel->certificateExpiresAt()?->toIso8601String(),
                    'error' => $panel->certificateError(),
                ],
                'url' => $name !== null && $panel->certificateUsable() ? "https://{$name}" : null,
            ],
        ]);
    }

    public function terms(Request $request, HttpsSettings $https, ConfigurePanelDomain $configure, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'agree' => ['accepted'],
            'email' => ['nullable', 'email', 'max:254'],
        ], ['agree.accepted' => 'Accept the Let\'s Encrypt Subscriber Agreement to turn on HTTPS, or skip this step.']);

        $https->enable($data['email'] ?? null);
        $audit->record('https.enabled', null, ['email' => $data['email'] ?? null, 'staging' => $https->staging()]);
        $configure->requestCertificate();

        return redirect()->route('welcome');
    }

    public function domain(Request $request, ConfigurePanelDomain $configure): RedirectResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:300']]);

        $configure->set($data['domain']);

        return redirect()->route('welcome');
    }

    public function check(ConfigurePanelDomain $configure): RedirectResponse
    {
        $configure->check(retry: true);

        return redirect()->route('welcome');
    }

    public function clear(ConfigurePanelDomain $configure): RedirectResponse
    {
        $configure->clear();

        return redirect()->route('welcome');
    }
}
