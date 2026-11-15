<?php

namespace App\FastApps;

use App\Models\App;

class Grafana extends QuickApp
{
    public function id(): string
    {
        return 'grafana';
    }

    public function name(): string
    {
        return 'Grafana';
    }

    public function description(): string
    {
        return __('Dashboards and charts for your data. Sign in as admin.');
    }

    protected function image(): string
    {
        return 'grafana/grafana:12.2.0';
    }

    public function containerPort(): int
    {
        return 3000;
    }

    public function healthPath(): string
    {
        return '/api/health';
    }

    public function volumePaths(): array
    {
        return ['/var/lib/grafana'];
    }

    public function environment(array $settings): array
    {
        return [
            'GF_SECURITY_ADMIN_USER' => 'admin',
            'GF_SECURITY_ADMIN_PASSWORD' => $this->secret(24),
        ];
    }

    protected function dockerfile(): string
    {
        return <<<DOCKER
        FROM {$this->image()}
        ENV GF_USERS_ALLOW_SIGN_UP=false GF_ANALYTICS_REPORTING_ENABLED=false GF_ANALYTICS_CHECK_FOR_UPDATES=false

        DOCKER;
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('User'), $this->variable($app, 'GF_SECURITY_ADMIN_USER')),
            $this->row(__('Password'), $this->variable($app, 'GF_SECURITY_ADMIN_PASSWORD'), true),
        ];
    }
}
