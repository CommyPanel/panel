<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\Traffic\AppRouting;
use App\Dns\CheckDomain;
use App\Dns\PanelDomain;
use App\Dns\ServerAddress;
use App\Models\Domain;
use App\Presenters\AppPresenter;
use App\Tls\HttpsSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function index(ServerAddress $server, HttpsSettings $https, PanelDomain $panel): Response
    {
        return Inertia::render('domains/index', [
            'panel' => [
                'domain' => $panel->name(),
                'dns_status' => $panel->dns()['status'] ?? null,
                'certificate' => $panel->certificateStatus(),
                'expires_at' => $panel->certificateExpiresAt()?->toIso8601String(),
            ],
            'server' => $server->forPage(),
            'https' => ['enabled' => $https->enabled(), 'email' => $https->email(), 'staging' => $https->staging()],
            'domains' => Domain::query()->with('app.certificate')->orderBy('name')->get()
                ->map(fn (Domain $domain) => AppPresenter::domain($domain))->values(),
        ]);
    }

    public function show(Domain $domain, ServerAddress $server): Response
    {
        $domain->load('app.certificate');
        $certificate = $domain->app?->certificate;

        return Inertia::render('domains/show', [
            'server' => $server->forPage(),
            'domain' => AppPresenter::domain($domain),
            'certificate' => $certificate === null ? null : [
                'status' => $certificate->status->value,
                'covers_domain' => in_array($domain->name, $certificate->domains ?? [], true),
                'domains' => $certificate->domains ?? [],
                'staging' => $certificate->staging,
                'issued_at' => $certificate->issued_at?->toIso8601String(),
                'expires_at' => $certificate->expires_at?->toIso8601String(),
                'last_error' => $certificate->last_error,
            ],
            'redirected_from' => Domain::query()->where('redirect_to', $domain->name)->orderBy('name')->pluck('name')->values(),
        ]);
    }

    public function check(Domain $domain, CheckDomain $check): RedirectResponse
    {
        $check($domain);

        return back();
    }

    public function destroy(Request $request, Domain $domain, AppRouting $routing, AuditLogger $audit): RedirectResponse
    {
        $app = $domain->app;
        $name = $domain->name;

        // Domains that redirected here serve the app themselves from now on.
        Domain::query()->where('redirect_to', $name)->update(['redirect_to' => null]);
        $domain->delete();
        $audit->record('domain.removed', null, ['domain' => $name]);

        if ($app !== null) {
            $routing->sync($app);
        }

        // From a domain's own page there is nothing to go back to.
        $redirect = $request->input('return') === 'domains' ? redirect()->route('domains.index') : back();

        return $redirect->with('status', "{$name} removed.");
    }
}
