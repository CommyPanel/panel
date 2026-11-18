<?php

namespace App\Dns;

use App\Audit\AuditLogger;
use App\Jobs\IssuePanelCertificateJob;
use App\Models\Domain;
use App\Tls\HttpsSettings;
use App\Tls\IssuePanelCertificate;
use App\Tls\PanelRouting;
use Illuminate\Validation\ValidationException;

/**
 * Chooses the domain the panel is served on, checks its DNS and queues the
 * certificate. Serving on the domain begins once the certificate exists.
 */
class ConfigurePanelDomain
{
    public function __construct(
        private readonly PanelDomain $panel,
        private readonly DomainVerifier $verifier,
        private readonly HttpsSettings $https,
        private readonly IssuePanelCertificate $issue,
        private readonly PanelRouting $routing,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function set(string $input): void
    {
        $name = DomainName::normalize($input);

        if ($name === null) {
            throw ValidationException::withMessages(['domain' => 'Enter a domain such as panel.example.com, without http://, paths or wildcards.']);
        }

        if (Domain::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['domain' => "{$name} is already connected to an app. Use a different name for the panel."]);
        }

        // While the panel is served on a domain, a new name only becomes a candidate:
        // the current domain, its certificate and its nginx site stay exactly as they
        // are until the new one has verified DNS and its own certificate.
        if ($this->panel->certificateUsable()) {
            if ($name === $this->panel->name()) {
                throw ValidationException::withMessages(['domain' => "The panel is already served on {$name}."]);
            }

            $this->panel->setNext($name);
            $this->audit->record('panel.domain_candidate_set', null, ['domain' => $name]);
            $this->checkNext();

            return;
        }

        $this->panel->set($name);
        $this->audit->record('panel.domain_set', null, ['domain' => $name]);
        $this->check();
    }

    public function checkNext(): void
    {
        $name = $this->panel->next();

        if ($name === null) {
            return;
        }

        $result = $this->verifier->check($name);
        $this->panel->recordNextDns($result->status->value, $result->toArray());
    }

    /**
     * Start getting the certificate for the candidate domain. Only a verified
     * candidate qualifies, and only when HTTPS is on.
     *
     * @throws ValidationException
     */
    public function switchToNext(): void
    {
        $this->checkNext();

        if ($this->panel->next() === null || ! $this->panel->nextDnsVerified()) {
            throw ValidationException::withMessages(['domain' => 'The new domain\'s DNS is not verified yet.']);
        }

        if (! $this->https->enabled()) {
            throw ValidationException::withMessages(['domain' => 'Turn on HTTPS first.']);
        }

        if ($this->panel->nextStatus() === 'issuing') {
            return;
        }

        $this->panel->markNextIssuing();
        $this->audit->record('panel.domain_switch_started', null, ['domain' => $this->panel->next()]);
        IssuePanelCertificateJob::dispatch(true);
    }

    public function cancelNext(): void
    {
        if ($this->panel->next() === null || $this->panel->nextStatus() === 'issuing') {
            return;
        }

        $this->panel->setNext(null);
        $this->audit->record('panel.domain_candidate_removed');
    }

    public function check(bool $retry = false): void
    {
        $name = $this->panel->name();

        if ($name === null) {
            return;
        }

        $result = $this->verifier->check($name);
        $this->panel->recordDns($result->status->value, $result->toArray());

        $this->requestCertificate($retry);
    }

    /**
     * Queue issuance when everything it needs is in place.
     *
     * @param  bool  $retry  the user asked again, so a recent failure does not hold it back
     */
    public function requestCertificate(bool $retry = false): void
    {
        if (! $this->https->enabled() || ! $this->panel->dnsVerified()) {
            return;
        }

        $again = $retry && ! $this->panel->certificateUsable() && $this->panel->certificateStatus() !== 'issuing';

        if ($again || $this->issue->needed()) {
            IssuePanelCertificateJob::dispatch();
        }
    }

    public function clear(): void
    {
        $this->panel->setNext(null);
        $this->panel->set(null);
        $this->routing->sync();
        $this->audit->record('panel.domain_removed');
    }
}
