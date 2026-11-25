<?php

namespace App\FastApps;

use App\Enums\DeployStrategy;
use App\Models\App;

/**
 * Vaultwarden, a Bitwarden-compatible password manager. Everything (database, attachments, keys)
 * lives in one volume. It cannot run twice on that SQLite file, so a deploy stops the old version
 * first. The browser vault only works over HTTPS (it uses the browser's crypto), so it needs a domain
 * and its certificate. Sign-ups stay open until the owner has made an account and switches them off.
 */
class Vaultwarden extends QuickApp
{
    public function id(): string
    {
        return 'vaultwarden';
    }

    public function name(): string
    {
        return 'Vaultwarden';
    }

    public function description(): string
    {
        return __('A password manager that works with the Bitwarden apps and browser extensions. Your vault stays on your server.');
    }

    protected function image(): string
    {
        return 'vaultwarden/server:1.37.5';
    }

    public function containerPort(): int
    {
        return 80;
    }

    public function healthPath(): string
    {
        return '/alive';
    }

    public function deployStrategy(): DeployStrategy
    {
        return DeployStrategy::StopFirst;
    }

    public function volumePaths(): array
    {
        return ['/data'];
    }

    public function warnings(): array
    {
        return [
            __('Vaultwarden holds your passwords. Give it a domain and wait for its certificate: the vault in the browser does not work without HTTPS. Back up its volume (Volumes tab) before you rely on it.'),
            __('Anyone who can open it can make an account until you switch sign-ups off. Make your own account first, then set the variable SIGNUPS_ALLOWED to false on the Variables tab and apply.'),
        ];
    }

    public function environment(array $settings): array
    {
        return ['SIGNUPS_ALLOWED' => 'true'];
    }

    protected function dockerfile(): string
    {
        return <<<DOCKER
        FROM {$this->image()}
        ENV ROCKET_PORT=80 ROCKET_ADDRESS=0.0.0.0 DATA_FOLDER=/data SHOW_PASSWORD_HINT=false

        DOCKER;
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('Sign-ups allowed'), $this->variable($app, 'SIGNUPS_ALLOWED')),
        ];
    }
}
