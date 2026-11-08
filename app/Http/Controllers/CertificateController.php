<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Enums\CertificateStatus;
use App\Jobs\IssueAppCertificate;
use App\Models\App;
use App\Tls\HttpsSettings;
use App\Tls\TlsPlanner;
use Illuminate\Http\RedirectResponse;

class CertificateController extends Controller
{
    public function store(App $app, HttpsSettings $https, TlsPlanner $tls, AuditLogger $audit): RedirectResponse
    {
        if (! $https->enabled()) {
            return back()->withErrors(['certificate' => __('Turn on automatic HTTPS on the Domains page first.')]);
        }

        if ($tls->eligibleDomains($app) === []) {
            return back()->withErrors(['certificate' => __('Connect a domain to this app first. A certificate needs a domain whose DNS points at this server.')]);
        }

        $certificate = $app->certificate()->first();

        if ($certificate?->status === CertificateStatus::Issuing && $certificate->last_attempt_at?->isAfter(now()->subMinutes(15))) {
            return back()->withErrors(['certificate' => __('A certificate is already being requested.')]);
        }

        IssueAppCertificate::dispatch($app->id);
        $audit->record('tls.requested', null, ['app' => $app->slug], $app);

        return back()->with('status', __('Certificate requested. This usually takes under a minute.'));
    }
}
