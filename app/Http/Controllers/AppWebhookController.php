<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Enums\SourceType;
use App\Models\App;
use Illuminate\Http\RedirectResponse;

/**
 * Turns an app's push webhook on (or gives it a new secret) and off.
 */
class AppWebhookController extends Controller
{
    public function store(App $app, AuditLogger $audit): RedirectResponse
    {
        abort_unless($app->source_type === SourceType::Git && $app->setup_step === null, 404);

        $renewed = $app->webhook_id !== null;
        $app->forceFill([
            'webhook_id' => $app->webhook_id ?? bin2hex(random_bytes(16)),
            'webhook_secret' => bin2hex(random_bytes(24)),
        ])->save();

        $audit->record($renewed ? 'app.webhook_secret_renewed' : 'app.webhook_enabled', null, ['app' => $app->slug], $app);

        return back()->with('status', $renewed
            ? __('New secret created. Update it in your Git host; the old one no longer works.')
            : __('Deploy on push is on. Add the webhook to your repository.'));
    }

    public function destroy(App $app, AuditLogger $audit): RedirectResponse
    {
        abort_unless($app->webhook_id !== null, 404);

        $app->forceFill(['webhook_id' => null, 'webhook_secret' => null])->save();
        $audit->record('app.webhook_disabled', null, ['app' => $app->slug], $app);

        return back()->with('status', __('Deploy on push is off. You can remove the webhook from your repository.'));
    }
}
