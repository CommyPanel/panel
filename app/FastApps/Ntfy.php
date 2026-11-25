<?php

namespace App\FastApps;

use App\Enums\DeployStrategy;
use App\Models\App;

/**
 * ntfy, a push notification server: send a message with a plain HTTP request, read it on a phone or in
 * a browser. Nothing is open to the public: the default access is "deny all" and an `admin` user with a
 * generated password is made on every start (a small script runs `ntfy user add`, or changes the
 * password if the user exists), so the password in the variables is always the one that works.
 */
class Ntfy extends QuickApp
{
    public function id(): string
    {
        return 'ntfy';
    }

    public function name(): string
    {
        return 'ntfy';
    }

    public function description(): string
    {
        return __('Send push notifications to your phone or browser with a simple web request. Topics are private by default.');
    }

    protected function image(): string
    {
        return 'binwiederhier/ntfy:v2.38.2';
    }

    public function containerPort(): int
    {
        return 80;
    }

    public function healthPath(): string
    {
        return '/v1/health';
    }

    public function deployStrategy(): DeployStrategy
    {
        return DeployStrategy::StopFirst;
    }

    public function volumePaths(): array
    {
        return ['/var/lib/ntfy', '/var/cache/ntfy'];
    }

    public function warnings(): array
    {
        return [
            __('After you add a domain, add the variable NTFY_BASE_URL (https:// and the domain, without a closing slash) on the Variables tab and apply. The apps need it to subscribe.'),
        ];
    }

    public function environment(array $settings): array
    {
        return ['NTFY_ADMIN_PASSWORD' => $this->secret(24)];
    }

    public function files(App $app): array
    {
        $dockerfile = <<<DOCKER
        FROM {$this->image()}
        ENV NTFY_LISTEN_HTTP=:80 NTFY_BEHIND_PROXY=true NTFY_AUTH_FILE=/var/lib/ntfy/user.db NTFY_AUTH_DEFAULT_ACCESS=deny-all NTFY_CACHE_FILE=/var/cache/ntfy/cache.db
        COPY commypanel-start.sh /commypanel-start.sh
        ENTRYPOINT ["sh", "/commypanel-start.sh"]

        DOCKER;

        return ['Dockerfile' => $dockerfile, 'commypanel-start.sh' => self::START_SCRIPT];
    }

    /** Makes (or updates) the admin user from the password in the variables, then starts the server. The user command needs the file to exist; an empty file is a valid new database. */
    private const START_SCRIPT = <<<'SH'
    #!/bin/sh
    set -e
    [ -e "$NTFY_AUTH_FILE" ] || : > "$NTFY_AUTH_FILE"
    export NTFY_PASSWORD="$NTFY_ADMIN_PASSWORD"
    ntfy user add --role=admin admin >/dev/null 2>&1 || ntfy user change-pass admin >/dev/null
    unset NTFY_PASSWORD
    exec ntfy serve
    SH;

    public function linkEnvironment(App $target, string $prefix): array
    {
        return [
            "{$prefix}_USER" => 'admin',
            "{$prefix}_PASSWORD" => $this->variable($target, 'NTFY_ADMIN_PASSWORD'),
        ];
    }

    public function credentials(App $app): array
    {
        return [
            $this->row(__('Address for linked apps'), $this->internalAddress($app)),
            $this->row(__('User'), 'admin'),
            $this->row(__('Password'), $this->variable($app, 'NTFY_ADMIN_PASSWORD'), true),
        ];
    }
}
