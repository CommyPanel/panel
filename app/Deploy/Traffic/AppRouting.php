<?php

namespace App\Deploy\Traffic;

use App\Enums\CertificateStatus;
use App\Host\Data\NginxSite;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Deployment;

/**
 * Makes nginx match the database: an app with domains and a live deployment
 * gets a site that proxies to its container; anything else has none.
 */
class AppRouting
{
    public function __construct(private readonly HostDriver $host) {}

    /**
     * @param  Deployment|null  $target  the deployment about to go live, when it is not recorded as active yet
     */
    public function sync(App $app, ?Deployment $target = null): void
    {
        $site = $this->siteFor($app, $target);

        if ($site === null) {
            $this->host->removeNginxSite($app->slug);

            return;
        }

        $this->host->applyNginxSite($site);
    }

    /**
     * The nginx site the app should have right now, or null when it should
     * have none (no domain, or no live deployment to send visitors to).
     *
     * @param  Deployment|null  $target  the deployment about to go live, when it is not recorded as active yet
     */
    public function siteFor(App $app, ?Deployment $target = null): ?NginxSite
    {
        $live = $target ?? $app->activeDeployment()->first();
        $rows = $app->domains()->orderBy('name')->get();
        $domains = array_values(array_map(fn (mixed $name): string => (string) $name, $rows->pluck('name')->all()));
        $redirects = [];

        foreach ($rows as $row) {
            // A redirect only counts while its target is still one of the app's domains.
            if ($row->redirect_to !== null && in_array($row->redirect_to, $domains, true)) {
                $redirects[$row->name] = $row->redirect_to;
            }
        }

        // Never leave an app with nothing to serve.
        if (count($redirects) >= count($domains)) {
            $redirects = [];
        }

        if ($live === null || $live->host_port === null || $domains === []) {
            return null;
        }

        $certificate = $app->certificate()->first();
        $https = $certificate !== null && $certificate->status === CertificateStatus::Active && $certificate->isUsable();

        return new NginxSite($app->slug, $domains, $live->host_port, $https ? $app->slug : null, $redirects, $app->nginx_custom);
    }
}
