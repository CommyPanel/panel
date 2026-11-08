<?php

namespace App\Http\Controllers;

use App\Alerts\AlertDelivery;
use App\Alerts\Alerter;
use App\Alerts\AlertEvent;
use App\Alerts\AlertKind;
use App\Alerts\AlertTargets;
use App\Alerts\WebhookGuard;
use App\Audit\AuditLogger;
use App\Models\AlertTarget;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where alerts are sent. A target's address is write-only: stored encrypted and never shown
 * again, because chat webhooks and bot tokens carry their secret in it.
 *
 * Page props of `settings/notifications`: `targets` (id, name, kind, kind_label, host,
 * chat_id (masked or null), events, enabled, last_ok_at, last_error), `events` (key, label,
 * group, default_on), `kinds` (value, label, address_label, hint, needs_chat_id) and
 * `max_targets`.
 */
class NotificationController extends Controller
{
    public function index(AlertTargets $targets): Response
    {
        return Inertia::render('settings/notifications', [
            'targets' => $targets->targets(),
            'events' => $targets->events(),
            'kinds' => $targets->kinds(),
            'max_targets' => AlertTarget::MAX,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        if (AlertTarget::query()->count() >= AlertTarget::MAX) {
            throw ValidationException::withMessages(['name' => __('You can have up to :max places to send alerts to. Remove one first.', ['max' => AlertTarget::MAX])]);
        }

        $kind = AlertKind::tryFrom((string) $request->input('kind'));
        $data = $this->validated($request, $kind, addressRequired: true);

        $target = AlertTarget::query()->create([
            'name' => $data['name'],
            'kind' => $kind,
            'address' => $data['address'],
            'chat_id' => $kind?->needsChatId() ? $data['chat_id'] : null,
            'events' => $data['events'] ?? AlertEvent::defaults(),
            'enabled' => (bool) ($data['enabled'] ?? true),
        ]);

        $audit->record('notifications.changed', $request->user(), $this->summary($target, true) + ['created' => true]);

        return back()->with('status', __('Saved. Alerts are sent there from now on.'));
    }

    public function update(Request $request, AlertTarget $alertTarget, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request, $alertTarget->kind, addressRequired: false);
        $changed = ($data['address'] ?? '') !== '';

        $alertTarget->forceFill([
            'name' => $data['name'],
            'events' => $data['events'] ?? $alertTarget->events,
            'enabled' => (bool) ($data['enabled'] ?? $alertTarget->enabled),
            ...($changed ? ['address' => $data['address']] : []),
            ...($alertTarget->kind->needsChatId() && ($data['chat_id'] ?? '') !== '' ? ['chat_id' => $data['chat_id']] : []),
        ])->save();

        $audit->record('notifications.changed', $request->user(), $this->summary($alertTarget, $changed));

        return back()->with('status', __('Saved.'));
    }

    public function toggle(Request $request, AlertTarget $alertTarget, AuditLogger $audit): RedirectResponse
    {
        $alertTarget->forceFill(['enabled' => ! $alertTarget->enabled])->save();

        $audit->record('notifications.changed', $request->user(), $this->summary($alertTarget, false));

        return back()->with('status', $alertTarget->enabled ? __('Alerts are on for this place.') : __('Alerts are off for this place.'));
    }

    /**
     * Sends a test message to a saved target.
     */
    public function test(AlertTarget $alertTarget, Alerter $alerter): RedirectResponse
    {
        $error = $alerter->test($alertTarget);

        if ($error !== null) {
            return back()->withErrors(['alerts' => $error]);
        }

        return back()->with('status', __('The message was sent. Check the channel.'));
    }

    public function destroy(Request $request, AlertTarget $alertTarget, AuditLogger $audit): RedirectResponse
    {
        $summary = $this->summary($alertTarget, false);
        $alertTarget->delete();
        $audit->record('notifications.removed', $request->user(), $summary);

        return back()->with('status', __('Removed. Nothing is sent there any more.'));
    }

    /**
     * What goes in the audit log: never the address.
     *
     * @return array<string, mixed>
     */
    private function summary(AlertTarget $target, bool $addressChanged): array
    {
        return [
            'name' => $target->name,
            'kind' => $target->kind->value,
            'host' => $target->host(),
            'enabled' => $target->enabled,
            'events' => $target->events,
            'address_changed' => $addressChanged,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AlertKind $kind, bool $addressRequired): array
    {
        if ($kind === null) {
            throw ValidationException::withMessages(['kind' => __('Choose where the alerts should go.')]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'enabled' => ['boolean'],
            'events' => ['array'],
            'events.*' => ['string', Rule::in(AlertEvent::keys())],
            'address' => [$addressRequired ? 'required' : 'nullable', 'string', 'max:500', $this->address($kind)],
            'chat_id' => [$kind->needsChatId() && $addressRequired ? 'required' : 'nullable', 'string', 'max:40', $this->chatId($kind)],
        ]);
    }

    private function address(AlertKind $kind): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kind): void {
            if ($value === null || $value === '') {
                return;
            }

            if ($kind === AlertKind::Telegram) {
                if (preg_match(AlertDelivery::TOKEN, (string) $value) !== 1) {
                    $fail(__('That does not look like a bot token. It has digits, a colon and a long code.'));
                }

                return;
            }

            if (($problem = app(WebhookGuard::class)->problem((string) $value)) !== null) {
                $fail($problem);
            }
        };
    }

    private function chatId(AlertKind $kind): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kind): void {
            if ($kind->needsChatId() && $value !== null && $value !== '' && preg_match(AlertDelivery::CHAT_ID, (string) $value) !== 1) {
                $fail(__('Use the numeric chat id (it may start with a minus sign) or @channelname.'));
            }
        };
    }
}
