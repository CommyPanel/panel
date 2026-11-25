<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Enums\CertificateStatus;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
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
        $domains = $tls->eligibleDomains($app);
        $pending = $tls->wildcards($domains) !== [] && ! $tls->automaticDns() ? $tls->pending($certificate) : null;

        // Wildcard names without a Cloudflare token: nothing to do until the TXT records can be seen.
        if ($pending !== null && array_diff($domains, $pending['domains']) === [] && array_diff($pending['domains'], $domains) === [] && ! $tls->recordsVisible($pending)) {
            return back()->withErrors(['certificate' => __('The TXT record cannot be seen yet. DNS changes can take a few minutes to spread; check again in a moment.')]);
        }

        if ($certificate?->status === CertificateStatus::Issuing && $certificate->last_attempt_at?->isAfter(now()->subMinutes(15))) {
            return back()->withErrors(['certificate' => __('A certificate is already being requested.')]);
        }

        IssueAppCertificate::dispatch($app->id);
        $audit->record('tls.requested', null, ['app' => $app->slug], $app);

        return back()->with('status', __('Certificate requested. This usually takes under a minute.'));
    }

    /**
     * Throw away the waiting order and ask for fresh TXT records (the old ones were lost, mistyped or ran out).
     */
    public function refreshDns(App $app, HostDriver $host, HttpsSettings $https, TlsPlanner $tls, AuditLogger $audit): RedirectResponse
    {
        $certificate = $app->certificate()->first();

        if (! $https->enabled() || $certificate?->dns_pending === null) {
            return back()->withErrors(['certificate' => __('There is no waiting TXT record to replace.')]);
        }

        try {
            $host->cancelDnsCertificate($app->slug);
        } catch (HostOperationFailed) {
            // The agent forgets an order on its own when a new one is made.
        }

        $certificate->forceFill(['dns_pending' => null])->save();
        IssueAppCertificate::dispatch($app->id);
        $audit->record('tls.dns_refreshed', null, ['app' => $app->slug], $app);

        return back()->with('status', __('New TXT records are being requested.'));
    }
}
