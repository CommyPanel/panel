<?php

namespace App\FastApps;

use App\Models\App;

class Meilisearch extends QuickApp
{
    public function id(): string
    {
        return 'meilisearch';
    }

    public function name(): string
    {
        return 'Meilisearch';
    }

    public function description(): string
    {
        return __('Fast, typo-tolerant search for your site or app. Reached with a master key.');
    }

    protected function image(): string
    {
        return 'getmeili/meilisearch:v1.15';
    }

    public function containerPort(): int
    {
        return 7700;
    }

    public function healthPath(): string
    {
        return '/health';
    }

    public function volumePaths(): array
    {
        return ['/meili_data'];
    }

    public function environment(array $settings): array
    {
        return ['MEILI_MASTER_KEY' => $this->secret(40)];
    }

    protected function dockerfile(): string
    {
        return <<<DOCKER
        FROM {$this->image()}
        ENV MEILI_ENV=production MEILI_HTTP_ADDR=0.0.0.0:7700 MEILI_DB_PATH=/meili_data/data.ms MEILI_NO_ANALYTICS=true

        DOCKER;
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('Address for linked apps'), $this->internalAddress($app)),
            $this->row(__('Master key'), $this->variable($app, 'MEILI_MASTER_KEY'), true),
        ];
    }
}
