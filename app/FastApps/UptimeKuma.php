<?php

namespace App\FastApps;

use App\Models\App;

class UptimeKuma extends QuickApp
{
    public function id(): string
    {
        return 'uptime-kuma';
    }

    public function name(): string
    {
        return 'Uptime Kuma';
    }

    public function description(): string
    {
        return __('Watches your sites and apps from the outside and tells you when one goes down. Has status pages.');
    }

    protected function image(): string
    {
        return 'louislam/uptime-kuma:2.5.11';
    }

    public function containerPort(): int
    {
        return 3001;
    }

    public function healthPath(): string
    {
        // It redirects to the dashboard or the first-run page: below 500 means it is up.
        return '/';
    }

    public function healthTimeout(): ?int
    {
        // The first start makes its database.
        return 120;
    }

    public function volumePaths(): array
    {
        return ['/app/data'];
    }

    public function warnings(): array
    {
        return [];
    }

    public function environment(array $settings): array
    {
        return [];
    }

    protected function dockerfile(): string
    {
        // SQLite in the data volume: without this the first visit asks which database to use.
        return <<<DOCKER
        FROM {$this->image()}
        ENV UPTIME_KUMA_DB_TYPE=sqlite UPTIME_KUMA_HOST=0.0.0.0 UPTIME_KUMA_PORT=3001

        DOCKER;
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('Address for linked apps'), $this->internalAddress($app)),
        ];
    }
}
