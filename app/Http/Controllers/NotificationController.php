<?php

namespace App\Http\Controllers;

use App\Alerts\AlertFailed;
use App\Alerts\AlertSettings;
use App\Alerts\DeployAlerts;
use App\Alerts\WebhookGuard;
use App\Audit\AuditLogger;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the result of deployments is sent. The address is write-only: stored encrypted and
 * never shown again, because chat webhooks carry their secret in the address.
 */
class NotificationController extends Controller
{
    public function index(AlertSettings $settings): Response
    {
        return Inertia::render('settings/notifications', ['alerts' => $settings->toArray()]);
    }

    public function update(Request $request, AlertSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request, urlRequired: ! $settings->toArray()['has_url']);
        $settings->save($data);

        $audit->record('notifications.changed', $request->user(), [
            'enabled' => (bool) $data['enabled'],
            'format' => $data['format'],
            'events' => $data['events'],
            'host' => $settings->toArray()['host'],
            'address_changed' => ($data['url'] ?? '') !== '',
        ]);

        return back()->with('status', $data['enabled']
            ? __('Saved. Deployment results are sent there from now on.')
            : __('Saved. Messages are off.'));
    }

    /**
     * Sends a test message with what is in the form, saved or not.
     */
    public function test(Request $request, AlertSettings $settings, DeployAlerts $alerts): RedirectResponse
    {
        $data = $this->validated($request, urlRequired: ! $settings->toArray()['has_url']);

        // nothing is saved: the test uses the form, so it needs no password and changes nothing
        $url = ($data['url'] ?? '') !== '' ? $data['url'] : $settings->url();

        try {
            $alerts->test($url, $data['format']);
        } catch (AlertFailed $e) {
            return back()->withErrors(['alerts' => $e->getMessage()]);
        }

        return back()->with('status', __('The message was sent. Check the channel. Nothing was saved yet.'));
    }

    public function destroy(Request $request, AlertSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $settings->clear();
        $audit->record('notifications.removed', $request->user());

        return back()->with('status', __('The address was removed. No messages are sent.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $urlRequired): array
    {
        return $request->validate([
            'enabled' => ['boolean'],
            'format' => ['required', 'in:'.implode(',', AlertSettings::FORMATS)],
            'events' => ['required', 'in:'.implode(',', AlertSettings::EVENTS)],
            'url' => [$urlRequired ? 'required' : 'nullable', 'string', 'max:500', $this->address(...)],
        ]);
    }

    private function address(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (($problem = app(WebhookGuard::class)->problem((string) $value)) !== null) {
            $fail($problem);
        }
    }
}
