<?php

namespace App\FastApps;

use App\Enums\DeployStrategy;
use App\Models\App;

/**
 * Redis for the apps linked to it: a cache or a queue on a private network, never on a domain. It
 * runs with a generated password and keeps its data (append-only file) in a volume. It does not
 * speak HTTP, so its health check is a PING. The password goes into a config file the start script
 * writes inside the container (readable only by Redis), never onto the command line.
 *
 * Two Redis servers must never share one data folder, and a new release starts beside the old one,
 * so the start script takes a lock on the folder: a second one refuses to start and says why.
 */
class Redis extends QuickApp
{
    public const PORT = 6379;

    public function id(): string
    {
        return 'redis';
    }

    public function name(): string
    {
        return 'Redis';
    }

    public function description(): string
    {
        return __('A fast cache and queue for the apps you link to it. Private: it has no web address.');
    }

    protected function image(): string
    {
        return 'redis:8.10';
    }

    public function containerPort(): int
    {
        return self::PORT;
    }

    public function private(): bool
    {
        return true;
    }

    public function pingHealth(): bool
    {
        return true;
    }

    public function deployStrategy(): DeployStrategy
    {
        // It cannot run twice on the same data, so the old version has to stop before the new one starts.
        return DeployStrategy::StopFirst;
    }

    public function healthPath(): string
    {
        // Not used: the check is a PING.
        return '/';
    }

    public function healthTimeout(): ?int
    {
        // It reads its data file before it answers.
        return 120;
    }

    public function volumePaths(): array
    {
        return ['/data'];
    }

    public function warnings(): array
    {
        return [];
    }

    public function environment(array $settings): array
    {
        return ['REDIS_PASSWORD' => $this->secret(32)];
    }

    public function files(App $app): array
    {
        $dockerfile = <<<'DOCKER'
        FROM redis:8.10
        COPY commypanel-start.sh /usr/local/bin/commypanel-start.sh
        ENTRYPOINT ["sh", "/usr/local/bin/commypanel-start.sh"]

        DOCKER;

        return ['Dockerfile' => $dockerfile, 'commypanel-start.sh' => self::START_SCRIPT];
    }

    private const START_SCRIPT = <<<'SH'
    #!/bin/sh
    set -eu

    : "${REDIS_PASSWORD:?}"

    mkdir -p /data
    exec 9>/data/.commypanel.lock
    if ! flock -n 9; then
        echo "Another Redis is still using /data. Stop the app, then deploy again." >&2
        exit 1
    fi

    # The password is written to a file only Redis can read, so it is not on the command line.
    conf=/run/redis-commypanel.conf
    umask 077
    {
        echo "requirepass $REDIS_PASSWORD"
        echo "dir /data"
        echo "appendonly yes"
        echo "appendfsync everysec"
        echo "protected-mode yes"
        echo "bind * -::*"
    } > "$conf"
    chown redis:redis "$conf"

    # The lock stays held by this process (file descriptor 9) for as long as Redis runs.
    exec setpriv --reuid=redis --regid=redis --clear-groups redis-server "$conf"

    SH;

    public function linkEnvironment(App $target, string $prefix): array
    {
        $password = $this->variable($target, 'REDIS_PASSWORD');

        return [
            // The web-style default is wrong for Redis, so it is replaced.
            "{$prefix}_URL" => "redis://:{$password}@{$target->slug}:".self::PORT,
            "{$prefix}_PASSWORD" => $password,
        ];
    }

    public function credentials(App $app): array
    {
        $password = $this->variable($app, 'REDIS_PASSWORD');

        return [
            $this->row(__('Address for linked apps'), "redis://{$app->slug}:".self::PORT),
            $this->row(__('Password'), $password, true),
            $this->row(__('Connection URL'), "redis://:{$password}@{$app->slug}:".self::PORT, true),
        ];
    }
}
