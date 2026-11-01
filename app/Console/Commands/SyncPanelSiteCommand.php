<?php

namespace App\Console\Commands;

use App\Host\Exceptions\HostOperationFailed;
use App\Tls\PanelRouting;
use Illuminate\Console\Command;

class SyncPanelSiteCommand extends Command
{
    protected $signature = 'commypanel:sync-panel-site';

    protected $description = "Write the panel's own nginx site again (after an upgrade, so changes to it arrive)";

    /**
     * The panel's own-domain site is written by the agent when the domain or its
     * certificate changes, so a new release's changes to that site (such as the
     * terminal location) would only appear at the next such change. The
     * installer runs this after an upgrade. With no panel domain it does nothing.
     */
    public function handle(PanelRouting $routing): int
    {
        try {
            $routing->sync();
        } catch (HostOperationFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("The panel's nginx site is up to date.");

        return self::SUCCESS;
    }
}
