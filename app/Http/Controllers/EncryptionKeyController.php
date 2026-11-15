<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shows the panel's encryption key (APP_KEY) so a person can keep a copy: backups never hold it,
 * and without it a restored database's encrypted values cannot be read. The route needs the
 * `server.key` permission and a recent password confirmation; every visit is audited as a
 * high-risk action. `?setup=1` shows the page as the last step of the first run.
 */
class EncryptionKeyController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): Response
    {
        $audit->record('server.key_viewed', $request->user(), ['setup' => $request->boolean('setup')]);

        return Inertia::render('settings/encryption-key', [
            'key' => (string) config('app.key'),
            'setup' => $request->boolean('setup'),
        ]);
    }
}
