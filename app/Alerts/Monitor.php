<?php

namespace App\Alerts;

use App\Deploy\Health\HealthChecker;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Host\HostHealth;
use App\Models\AlertState;
use App\Models\App;
use App\Models\DatabaseServer;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Looks once a minute at what the panel runs and sends a message when something CHANGES, never
 * on every look: an app that stopped answering for three minutes in a row, a database server
 * whose container is gone for two looks, a disk that crossed a level. What it saw last time is
 * kept in `alert_states`. Apps that are stopped, being deployed, watched after a release or
 * rolled out are left alone, because they are not expected to answer.
 */
class Monitor
{
    /** Failed looks in a row (one a minute) before an app is called down. */
    public const APP_STRIKES = 3;

    public const DATABASE_STRIKES = 2;

    /** Percentage points below a threshold the disk must fall before it counts as recovered. */
    public const DISK_HYSTERESIS = 3;

    private const LEVELS = ['ok' => 0, 'warn' => 1, 'critical' => 2];

    public function __construct(
        private readonly Alerter $alerter,
        private readonly HealthChecker $health,
        private readonly HostDriver $host,
        private readonly HostHealth $hostHealth,
    ) {}

    public function run(): void
    {
        $this->apps();
        $this->databases();
        $this->disk();
    }

    public function apps(): void
    {
        $seen = [];

        foreach (App::query()->whereNotNull('active_deployment_id')->with('activeDeployment')->get() as $app) {
            $subject = "app:{$app->slug}";
            $live = $app->activeDeployment;

            if ($app->setup_step !== null || $app->stopped_at !== null || $live === null || $live->host_port === null) {
                continue; // not expected to answer: its state is forgotten below
            }

            $seen[] = $subject;
            $state = AlertState::for($subject);

            if ($app->isDeploying() || $live->watch_state === 'watching') {
                // A release is on its way; what happens to it is told by the deployment's own messages.
                if ($state->exists && $state->state !== 'down' && $state->strikes > 0) {
                    $state->forceFill(['strikes' => 0, 'since' => null])->save();
                }

                continue;
            }

            try {
                $healthy = $this->health->probe($live->host_port, $app->health_path);
            } catch (Throwable) {
                $healthy = false;
            }

            if ($healthy) {
                if ($state->state === 'down') {
                    $this->alerter->send(new AlertMessage(
                        AlertEvent::AppRecovered,
                        "{$app->name} is answering again",
                        ['It was down for '.$this->duration($state->since).'.'],
                        route('apps.show', ['app' => $app->slug]),
                        $app->slug,
                        ['down_seconds' => $state->since === null ? null : (int) abs(now()->diffInSeconds($state->since))],
                    ));
                }

                if ($state->exists) {
                    $state->forceFill(['state' => 'ok', 'strikes' => 0, 'since' => null])->save();
                }

                continue;
            }

            $strikes = $state->strikes + 1;
            $since = $state->strikes === 0 || $state->since === null ? now() : $state->since;

            if ($state->state !== 'down' && $strikes >= self::APP_STRIKES) {
                $this->alerter->send(new AlertMessage(
                    AlertEvent::AppDown,
                    "{$app->name} is not answering",
                    ["It has not answered on {$app->health_path} for ".self::APP_STRIKES.' minutes in a row.'],
                    route('apps.show', ['app' => $app->slug]),
                    $app->slug,
                    ['health_path' => $app->health_path],
                ));
                $state->state = 'down';
            }

            $state->forceFill(['strikes' => $strikes, 'since' => $since])->save();
        }

        $this->forget('app:', $seen);
    }

    public function databases(): void
    {
        $servers = DatabaseServer::query()->where('status', 'running')->orderBy('id')->get();

        if ($servers->isEmpty()) {
            $this->forget('db:', []);

            return;
        }

        try {
            $running = [];

            foreach ($this->host->listContainers() as $container) {
                if ($container->state === 'running') {
                    $running[$container->name] = true;
                }
            }
        } catch (HostOperationFailed) {
            return; // Docker itself is down: the panel says so on its own, and nothing is learned about the servers
        }

        $seen = [];

        foreach ($servers as $server) {
            $subject = "db:{$server->slug}";
            $seen[] = $subject;
            $state = AlertState::for($subject);

            if (isset($running[$server->containerName()])) {
                if ($state->state === 'down') {
                    $this->alerter->send(new AlertMessage(
                        AlertEvent::DatabaseRecovered,
                        "Database server {$server->name} is running again",
                        ['It was down for '.$this->duration($state->since).'.'],
                        route('databases.show', ['server' => $server->slug]),
                        null,
                        ['database_server' => $server->slug, 'engine' => $server->engine->value],
                    ));
                }

                if ($state->exists) {
                    $state->forceFill(['state' => 'ok', 'strikes' => 0, 'since' => null])->save();
                }

                continue;
            }

            $strikes = $state->strikes + 1;
            $since = $state->strikes === 0 || $state->since === null ? now() : $state->since;

            if ($state->state !== 'down' && $strikes >= self::DATABASE_STRIKES) {
                $this->alerter->send(new AlertMessage(
                    AlertEvent::DatabaseStopped,
                    "Database server {$server->name} stopped",
                    ["Its container {$server->containerName()} is not running, but the panel expects it to."],
                    route('databases.show', ['server' => $server->slug]),
                    null,
                    ['database_server' => $server->slug, 'engine' => $server->engine->value],
                ));
                $state->state = 'down';
            }

            $state->forceFill(['strikes' => $strikes, 'since' => $since])->save();
        }

        $this->forget('db:', $seen);
    }

    public function disk(): void
    {
        $disk = $this->hostHealth->status()['disk'];

        if ($disk === null) {
            return;
        }

        $used = $disk['used_percent'];
        $warn = (int) config('commypanel.disk.warn_percent');
        $critical = (int) config('commypanel.disk.critical_percent');

        $state = AlertState::for('disk');
        $last = self::LEVELS[$state->state] ?? 0;
        $now = match (true) {
            $used >= $critical => 2,
            $used >= $warn => 1,
            default => 0,
        };

        if ($now < $last) {
            // Falling back needs a few points of room, so a disk hovering at a limit is quiet.
            $now = match (true) {
                $used >= $critical - self::DISK_HYSTERESIS => $last,
                $used >= $warn => 1,
                $used >= $warn - self::DISK_HYSTERESIS => min($last, 1),
                default => 0,
            };
        }

        if ($now === $last) {
            return;
        }

        $free = $this->size($disk['free_bytes']).' of '.$this->size($disk['total_bytes']).' free';
        $link = route('server.index');
        $data = ['used_percent' => $used, 'free_bytes' => $disk['free_bytes'], 'total_bytes' => $disk['total_bytes']];

        if ($now > $last) {
            $this->alerter->send($now === 2
                ? new AlertMessage(AlertEvent::DiskCritical, "The disk is almost full ({$used}%)", ["{$free}. Builds, backups and databases stop working when it is full."], $link, null, $data)
                : new AlertMessage(AlertEvent::DiskWarning, "The disk is getting full ({$used}%)", ["{$free}. Remove old images and backups before it fills up."], $link, null, $data));
        } elseif ($now === 0) {
            $this->alerter->send(new AlertMessage(AlertEvent::DiskRecovered, "The disk has room again ({$used}% used)", [$free.'.'], $link, null, $data));
        }

        $state->forceFill(['state' => array_search($now, self::LEVELS, true), 'since' => now()])->save();
    }

    /**
     * Forget the states of things that are no longer watched (stopped, deleted), without a message.
     *
     * @param  list<string>  $keep
     */
    private function forget(string $prefix, array $keep): void
    {
        AlertState::query()->where('subject', 'like', $prefix.'%')->whereNotIn('subject', $keep)->delete();
    }

    private function duration(?CarbonInterface $since): string
    {
        return $since === null ? 'a while' : $since->locale('en')->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE]);
    }

    private function size(int $bytes): string
    {
        return round($bytes / 1_000_000_000, 1).' GB';
    }
}
