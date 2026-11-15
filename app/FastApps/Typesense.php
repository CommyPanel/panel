<?php

namespace App\FastApps;

use App\Models\App;

class Typesense extends QuickApp
{
    public function id(): string
    {
        return 'typesense';
    }

    public function name(): string
    {
        return 'Typesense';
    }

    public function description(): string
    {
        return __('Fast, typo-tolerant search with an easy API. Reached with an API key.');
    }

    protected function image(): string
    {
        return 'typesense/typesense:28.0';
    }

    public function containerPort(): int
    {
        return 8108;
    }

    public function healthPath(): string
    {
        return '/health';
    }

    public function volumePaths(): array
    {
        return ['/data'];
    }

    public function environment(array $settings): array
    {
        return ['TYPESENSE_API_KEY' => $this->secret(40)];
    }

    protected function dockerfile(): string
    {
        return <<<DOCKER
        FROM {$this->image()}
        ENV TYPESENSE_DATA_DIR=/data TYPESENSE_API_PORT=8108

        DOCKER;
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('Address for linked apps'), $this->internalAddress($app)),
            $this->row(__('API key'), $this->variable($app, 'TYPESENSE_API_KEY'), true),
        ];
    }
}
