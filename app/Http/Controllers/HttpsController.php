<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\App;
use App\Tls\HttpsSettings;
use App\Tls\TlsPlanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HttpsController extends Controller
{
    public function update(Request $request, HttpsSettings $https, TlsPlanner $tls, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'agree' => ['required', 'boolean'],
            'email' => ['nullable', 'email', 'max:254'],
        ]);

        if (! $data['agree']) {
            $https->disable();
            $audit->record('https.disabled');

            return back()->with('status', __('Automatic HTTPS is off. Existing certificates keep working until they expire.'));
        }

        $https->enable($data['email'] ?? null);
        $audit->record('https.enabled', null, ['email' => $data['email'] ?? null, 'staging' => $https->staging()]);

        foreach (App::query()->has('domains')->cursor() as $app) {
            $tls->ensure($app);
        }

        return back()->with('status', __('Automatic HTTPS is on. Certificates are requested as domains connect.'));
    }
}
