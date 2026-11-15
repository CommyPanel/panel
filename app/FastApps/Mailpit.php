<?php

namespace App\FastApps;

use App\Models\App;

/**
 * A mail catcher for test and staging servers: apps send mail to its SMTP port over a link and
 * nothing leaves the server. The SMTP port is never published, so only linked apps reach it; the
 * web inbox is behind a generated user and password.
 */
class Mailpit extends QuickApp
{
    public const SMTP_PORT = 1025;

    public function id(): string
    {
        return 'mailpit';
    }

    public function name(): string
    {
        return 'Mailpit';
    }

    public function description(): string
    {
        return __('Catches the email your test apps send, so none of it leaves the server. Read it in a web inbox.');
    }

    protected function image(): string
    {
        return 'axllent/mailpit:v1.33';
    }

    public function containerPort(): int
    {
        return 8025;
    }

    public function healthPath(): string
    {
        // Answers without the inbox password.
        return '/livez';
    }

    public function volumePaths(): array
    {
        return ['/data'];
    }

    public function environment(array $settings): array
    {
        return ['MP_UI_AUTH' => 'admin:'.$this->secret(24)];
    }

    protected function dockerfile(): string
    {
        return <<<DOCKER
        FROM {$this->image()}
        ENV MP_DATABASE=/data/mailpit.db MP_MAX_MESSAGES=5000 MP_DISABLE_VERSION_CHECK=true MP_SMTP_BIND_ADDR=0.0.0.0:1025 MP_UI_BIND_ADDR=0.0.0.0:8025

        DOCKER;
    }

    public function linkEnvironment(App $target, string $prefix): array
    {
        return [
            "{$prefix}_SMTP_HOST" => $target->slug,
            "{$prefix}_SMTP_PORT" => (string) self::SMTP_PORT,
        ];
    }

    public function credentials(App $app): array
    {
        [$user, $password] = array_pad(explode(':', $this->variable($app, 'MP_UI_AUTH'), 2), 2, '');

        return [
            $this->row(__('SMTP address for linked apps'), $app->slug.':'.self::SMTP_PORT),
            $this->row(__('Address for linked apps'), $this->internalAddress($app)),
            $this->row(__('User'), $user),
            $this->row(__('Password'), $password, true),
        ];
    }
}
