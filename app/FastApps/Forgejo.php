<?php

namespace App\FastApps;

use App\Enums\DeployStrategy;
use App\Models\App;

/**
 * Forgejo, a small Git host with issues and pull requests (a community fork of Gitea). It runs the
 * rootless image with SQLite and keeps everything (repositories, database, and its settings file with the
 * generated keys, so sessions and two-factor secrets survive a deployment) in one volume.
 * Only HTTP is published, so Git works over HTTPS and not over SSH; SSH is switched off so it never
 * shows a clone address that cannot work.
 */
class Forgejo extends QuickApp
{
    public function id(): string
    {
        return 'forgejo';
    }

    public function name(): string
    {
        return 'Forgejo';
    }

    public function description(): string
    {
        return __('Host your own Git repositories with issues and pull requests. Clone and push over HTTPS.');
    }

    protected function image(): string
    {
        return 'codeberg.org/forgejo/forgejo:17.0.2-rootless';
    }

    public function containerPort(): int
    {
        return 3000;
    }

    public function healthPath(): string
    {
        return '/api/healthz';
    }

    public function healthTimeout(): ?int
    {
        // The first start makes its database and keys.
        return 180;
    }

    public function deployStrategy(): DeployStrategy
    {
        return DeployStrategy::StopFirst;
    }

    public function volumePaths(): array
    {
        return ['/var/lib/gitea'];
    }

    public function warnings(): array
    {
        return [
            __('The first account anyone makes becomes the administrator, so open Forgejo as soon as it runs. Then set the variable FORGEJO__service__DISABLE_REGISTRATION to true on the Variables tab and apply, so nobody else can sign up.'),
            __('After you add a domain, add the variable FORGEJO__server__ROOT_URL (https:// and the domain, with a closing slash) on the Variables tab and apply, so clone addresses show the right name. Git works over HTTPS only: CommyPanel does not publish an SSH port.'),
        ];
    }

    public function environment(array $settings): array
    {
        return ['FORGEJO__service__DISABLE_REGISTRATION' => 'false'];
    }

    protected function dockerfile(): string
    {
        return <<<DOCKER
        FROM {$this->image()}
        ENV FORGEJO__database__DB_TYPE=sqlite3 FORGEJO__security__INSTALL_LOCK=true FORGEJO__server__DISABLE_SSH=true FORGEJO__server__HTTP_PORT=3000 FORGEJO__server__PROTOCOL=http FORGEJO__log__MODE=console FORGEJO__other__SHOW_FOOTER_VERSION=false FORGEJO__service__ENABLE_NOTIFY_MAIL=false FORGEJO__mailer__ENABLED=false

        DOCKER;
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('Address for linked apps'), $this->internalAddress($app)),
            $this->row(__('Registration closed'), $this->variable($app, 'FORGEJO__service__DISABLE_REGISTRATION')),
        ];
    }
}
