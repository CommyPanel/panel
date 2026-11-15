<?php

namespace App\FastApps;

use App\Models\App;

/**
 * Gotenberg turns HTML, Office documents and URLs into PDFs and screenshots for your other apps.
 * It keeps nothing. Its API is behind a generated user and password and is meant to be reached over
 * a link, not from the internet.
 */
class Gotenberg extends QuickApp
{
    public function id(): string
    {
        return 'gotenberg';
    }

    public function name(): string
    {
        return 'Gotenberg';
    }

    public function description(): string
    {
        return __('Turns web pages, HTML and Office files into PDFs for your other apps. Reached over a link, keeps no data.');
    }

    protected function image(): string
    {
        return 'gotenberg/gotenberg:9.5';
    }

    public function containerPort(): int
    {
        return 3000;
    }

    public function healthPath(): string
    {
        // Answers without the API password.
        return '/health';
    }

    public function healthTimeout(): ?int
    {
        return 90;
    }

    public function memoryLimitMb(): ?int
    {
        return 1024;
    }

    public function volumePaths(): array
    {
        return [];
    }

    public function warnings(): array
    {
        return [
            __('Gotenberg runs a browser and an office suite inside it and needs about 1 GB of memory. Link it from the apps that use it instead of giving it a domain.'),
        ];
    }

    public function environment(array $settings): array
    {
        return [
            'API_ENABLE_BASIC_AUTH' => 'true',
            'GOTENBERG_API_BASIC_AUTH_USERNAME' => 'gotenberg',
            'GOTENBERG_API_BASIC_AUTH_PASSWORD' => $this->secret(24),
        ];
    }

    public function linkEnvironment(App $target, string $prefix): array
    {
        return [
            "{$prefix}_USER" => $this->variable($target, 'GOTENBERG_API_BASIC_AUTH_USERNAME'),
            "{$prefix}_PASSWORD" => $this->variable($target, 'GOTENBERG_API_BASIC_AUTH_PASSWORD'),
        ];
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('Address for linked apps'), $this->internalAddress($app)),
            $this->row(__('User'), $this->variable($app, 'GOTENBERG_API_BASIC_AUTH_USERNAME')),
            $this->row(__('Password'), $this->variable($app, 'GOTENBERG_API_BASIC_AUTH_PASSWORD'), true),
        ];
    }
}
