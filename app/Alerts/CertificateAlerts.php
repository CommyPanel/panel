<?php

namespace App\Alerts;

use App\Dns\PanelDomain;
use App\Models\AlertState;
use App\Models\App;
use App\Models\Certificate;
use Illuminate\Support\Str;

/**
 * Messages about certificates: an issue or renewal that failed (the reason is the one already
 * made readable by the agent, never raw exception text), and a certificate that is about to
 * expire although renewal should have happened by now.
 */
class CertificateAlerts
{
    /** A certificate this close to its end, still not renewed, is worth a message. */
    public const EXPIRING_DAYS = 14;

    /** The same failure is not told again for this long (the panel retries every few hours). */
    public const FAILURE_QUIET_HOURS = 24;

    public const EXPIRING_QUIET_DAYS = 3;

    public function __construct(private readonly Alerter $alerter) {}

    /**
     * An attempt failed. `$app` is null for the panel's own certificate.
     */
    public function failed(?App $app, string $reason): void
    {
        $subject = 'cert:'.($this->subject($app));
        $state = AlertState::for($subject);

        if ($state->state === 'failed' && $state->notified_at?->isAfter(now()->subHours(self::FAILURE_QUIET_HOURS))) {
            return;
        }

        $name = $app === null ? 'the panel' : $app->name;
        $this->alerter->send(new AlertMessage(
            AlertEvent::CertificateFailed,
            "The certificate for {$name} could not be issued or renewed",
            [Str::limit($reason, 300)],
            $this->link($app),
            $app?->slug,
            ['subject' => $this->subject($app), 'reason' => Str::limit($reason, 300)],
        ));

        $state->forceFill(['state' => 'failed', 'notified_at' => now(), 'since' => $state->since ?? now()])->save();
    }

    /**
     * An attempt worked: the next failure is a new one.
     */
    public function issued(?App $app): void
    {
        AlertState::query()->where('subject', 'cert:'.($this->subject($app)))->delete();
    }

    /**
     * Run daily: certificates that are close to their end and still not renewed.
     */
    public function checkExpiring(): void
    {
        $limit = now()->addDays(self::EXPIRING_DAYS);

        foreach (Certificate::query()->whereNotNull('expires_at')->where('expires_at', '<', $limit)->with('app')->get() as $certificate) {
            $app = $certificate->app;

            if ($app === null || ! $app->domains()->exists()) {
                continue; // the certificate is not in use
            }

            $this->expiring($app, $app->name, $certificate->expires_at, $certificate->domains ?? [], $certificate->last_error);
        }

        $panel = app(PanelDomain::class);
        $expires = $panel->certificateExpiresAt();

        if ($panel->name() !== null && $expires !== null && $expires->lt($limit)) {
            $this->expiring(null, 'the panel', $expires, [$panel->name()], $panel->certificateError());
        }
    }

    /**
     * @param  list<string>  $domains
     */
    private function expiring(?App $app, string $name, \DateTimeInterface $expiresAt, array $domains, ?string $error): void
    {
        $state = AlertState::for('cert-expiring:'.($this->subject($app)));

        if ($state->notified_at?->isAfter(now()->subDays(self::EXPIRING_QUIET_DAYS))) {
            return;
        }

        $days = (int) floor(now()->diffInDays($expiresAt, false));
        $when = $days < 0 ? 'has expired' : ($days === 0 ? 'expires today' : "expires in {$days} day".($days === 1 ? '' : 's'));
        $lines = ['It should have been renewed by now. Domains: '.implode(', ', $domains).'.'];

        if ($error !== null && $error !== '') {
            $lines[] = 'Last problem: '.Str::limit($error, 300);
        }

        $this->alerter->send(new AlertMessage(
            AlertEvent::CertificateExpiring,
            "The certificate for {$name} {$when}",
            $lines,
            $this->link($app),
            $app?->slug,
            ['subject' => $this->subject($app), 'days_left' => $days, 'domains' => $domains],
        ));

        $state->forceFill(['state' => 'notified', 'notified_at' => now()])->save();
    }

    private function subject(?App $app): string
    {
        return $app === null ? PanelDomain::SLUG : $app->slug;
    }

    private function link(?App $app): string
    {
        return $app === null ? route('domains.index') : route('apps.show', ['app' => $app->slug]);
    }
}
