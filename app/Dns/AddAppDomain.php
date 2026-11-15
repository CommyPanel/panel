<?php

namespace App\Dns;

use App\Audit\AuditLogger;
use App\Deploy\Traffic\AppRouting;
use App\Enums\DomainStatus;
use App\FastApps\FastApps;
use App\Models\App;
use App\Models\Domain;
use App\Tls\TlsPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Connects a domain to an app, optionally together with its www/apex
 * partner and a redirect between the two.
 */
class AddAppDomain
{
    public const NONE = 'none';

    /** The www name is the real one; the bare domain redirects to it. */
    public const WWW = 'www';

    /** The bare domain is the real one; www redirects to it. */
    public const APEX = 'apex';

    public function __construct(
        private readonly CheckDomain $check,
        private readonly PanelDomain $panel,
        private readonly AppRouting $routing,
        private readonly TlsPlanner $tls,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Takes a free name (left by a deleted app) for this app, or makes a new one. A free name starts
     * over: its old redirect and check belong to the app it had.
     */
    private function claim(string $name, App $app, ?string $redirectTo): Domain
    {
        $free = Domain::query()->where('name', $name)->whereNull('app_id')->first();

        if ($free === null) {
            return Domain::query()->create(['name' => $name, 'app_id' => $app->id, 'redirect_to' => $redirectTo]);
        }

        $free->forceFill([
            'app_id' => $app->id,
            'redirect_to' => $redirectTo,
            'status' => DomainStatus::Pending,
            'last_check' => null,
            'last_checked_at' => null,
            'verified_at' => null,
        ])->save();

        return $free;
    }

    /**
     * @return list<Domain> the domains that were connected (new, or free ones taken over)
     *
     * @throws ValidationException
     */
    public function __invoke(App $app, string $input, string $redirect): array
    {
        $name = DomainName::normalize($input);

        if ($name === null) {
            throw ValidationException::withMessages(['name' => 'Enter a domain such as app.example.com, without http://, paths or wildcards.']);
        }

        if (FastApps::find($app->template)?->private()) {
            throw ValidationException::withMessages(['name' => 'This app has no web interface, so it takes no domain. Other apps reach it through a link.']);
        }

        $hasWww = str_starts_with($name, 'www.');
        $partner = match ($redirect) {
            self::WWW => $hasWww ? null : 'www.'.$name,
            self::APEX => $hasWww ? substr($name, 4) : null,
            default => null,
        };

        if ($redirect === self::WWW && $hasWww) {
            throw ValidationException::withMessages(['redirect' => 'That name already starts with www. Enter the bare domain, or pick another option.']);
        }

        if ($redirect === self::APEX && ! $hasWww) {
            throw ValidationException::withMessages(['redirect' => 'Enter the www name (for example www.example.com) to redirect it to the bare domain.']);
        }

        if ($partner !== null && DomainName::normalize($partner) === null) {
            throw ValidationException::withMessages(['name' => 'That domain is too long to add a www name.']);
        }

        $names = array_values(array_filter([$name, $partner]));

        foreach ($names as $candidate) {
            if ($this->panel->isTaken($candidate)) {
                throw ValidationException::withMessages(['name' => "{$candidate} is the panel's own domain and cannot be used for an app."]);
            }

            // A name left behind by a deleted app (no app any more) is free to use again.
            if (Domain::query()->where('name', $candidate)->whereNotNull('app_id')->exists()) {
                throw ValidationException::withMessages(['name' => "{$candidate} is already connected to an app on this server."]);
            }
        }

        $created = DB::transaction(function () use ($app, $name, $partner) {
            $created = [];

            // The name that was typed redirects to its partner (example.com to www.example.com, or the reverse).
            $created[] = $this->claim($name, $app, $partner);

            if ($partner !== null) {
                $created[] = $this->claim($partner, $app, null);
            }

            return $created;
        });

        foreach ($created as $domain) {
            $this->audit->record('domain.added', null, ['domain' => $domain->name, 'app' => $app->slug, 'redirect_to' => $domain->redirect_to], $domain);
            ($this->check)($domain);
        }

        $this->routing->sync($app);
        $this->tls->ensure($app);

        return $created;
    }
}
