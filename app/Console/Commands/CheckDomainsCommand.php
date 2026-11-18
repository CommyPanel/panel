<?php

namespace App\Console\Commands;

use App\Dns\CheckDomain;
use App\Dns\ConfigurePanelDomain;
use App\Dns\PanelDomain;
use App\Enums\DomainStatus;
use App\Models\Domain;
use Illuminate\Console\Command;

class CheckDomainsCommand extends Command
{
    protected $signature = 'commypanel:check-domains';

    protected $description = 'Re-check DNS for domains that are not connected yet, and connected ones hourly';

    public function handle(CheckDomain $check, PanelDomain $panel, ConfigurePanelDomain $configure): int
    {
        $domains = Domain::query()
            ->where(fn ($query) => $query
                ->where('status', '!=', DomainStatus::Verified)
                ->orWhereNull('last_checked_at')
                ->orWhere('last_checked_at', '<', now()->subHour()))
            ->get();

        foreach ($domains as $domain) {
            $check($domain);
        }

        // The panel's own domain, until its certificate is in place.
        if ($panel->name() !== null && ! $panel->certificateUsable()) {
            $configure->check();
        }

        // A domain the panel is being moved to, until it is verified.
        if ($panel->next() !== null && ! $panel->nextDnsVerified()) {
            $configure->checkNext();
        }

        $this->components->info("Checked {$domains->count()} domain(s).");

        return self::SUCCESS;
    }
}
