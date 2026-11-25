<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Dns\CloudflareSettings;
use App\Dns\DomainName;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Tls\TlsPlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Cloudflare API token for wildcard certificates (Domains > Wildcard certificates). Saving asks
 * for the password and tests the token against a domain first, so a wrong or too weak token is never
 * stored; the token is never shown again.
 */
class CloudflareController extends Controller
{
    public function update(Request $request, CloudflareSettings $cloudflare, HostDriver $host, TlsPlanner $tls, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'domain' => ['required', 'string', 'max:253'],
        ]);

        $token = trim($data['token']);

        if (! CloudflareSettings::looksValid($token)) {
            return back()->withErrors(['token' => __('That does not look like a Cloudflare API token. Make one under My Profile > API Tokens and paste it here.')]);
        }

        $domain = DomainName::normalizeAllowingWildcard($data['domain']);

        if ($domain === null) {
            return back()->withErrors(['domain' => __('Enter a domain that is in your Cloudflare account, such as example.com.')]);
        }

        try {
            $zone = $host->checkCloudflare($token, ltrim($domain, '*.'));
        } catch (HostOperationFailed $e) {
            return back()->withErrors(['token' => $this->readable($e)]);
        }

        $cloudflare->save($token, $zone);
        $audit->record('dns.cloudflare_saved', $request->user(), ['zone' => $zone]);

        // Waiting wildcard orders no longer need the person's TXT records.
        foreach (App::query()->has('domains')->cursor() as $app) {
            $tls->ensure($app);
        }

        return back()->with('status', __('Cloudflare is connected. Wildcard certificates are now made and renewed automatically for zones this token can edit.'));
    }

    public function destroy(Request $request, CloudflareSettings $cloudflare, AuditLogger $audit): RedirectResponse
    {
        $cloudflare->forget();
        $audit->record('dns.cloudflare_removed', $request->user());

        return back()->with('status', __('Cloudflare is disconnected. Wildcard certificates will ask for a TXT record you create by hand.'));
    }

    private function readable(HostOperationFailed $e): string
    {
        return preg_replace('/^Agent error: /', '', $e->getMessage()) ?? $e->getMessage();
    }
}
