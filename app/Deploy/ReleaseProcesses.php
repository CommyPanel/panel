<?php

namespace App\Deploy;

use App\Databases\AppLinks;
use App\Host\Data\ContainerSpec;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppProcess;
use App\Models\Deployment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

/**
 * The containers of a release other than the web one: workers, the scheduler, Horizon ... Every
 * enabled process of the app is a container from the release's own image with its own command,
 * sharing the web container's variables, volumes, links and network, and publishing nothing.
 *
 * Which of them starts when: a process that may run beside the old release (`overlap`, a queue
 * worker) starts with the web container, before traffic moves. One that must not run twice (the
 * scheduler, Horizon: `stop_first`) starts only after traffic has moved and the old one has stopped.
 * The containers of a release are written to `deployments.processes` before anything starts, so
 * every later step (rollback, stop, delete, a failed deployment's clean-up) knows them.
 *
 * Entry of `deployments.processes`: name, kind, overlap, replicas, command (the text), memory_limit_mb,
 * grace (seconds), container (the first container's name), containers (all names), started (whether
 * the containers were made).
 */
class ReleaseProcesses
{
    public function __construct(
        private readonly HostDriver $host,
        private readonly AppVolumes $volumes,
        private readonly AppLinks $links,
    ) {}

    /**
     * What the web container and the processes are given as environment: the app's own variables
     * come last, so one with the same name wins on purpose.
     *
     * @return array<string, string>
     */
    public function environment(App $app): array
    {
        return ['PORT' => (string) $app->container_port, ...$this->links->environment($app), ...$app->environment()];
    }

    /**
     * Write down the containers this release will have (the enabled processes), before any starts.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DeploymentFailed when a command cannot be run
     */
    public function plan(App $app, Deployment $deployment): array
    {
        $entries = [];

        foreach ($app->processes()->where('enabled', true)->get() as $process) {
            try {
                $process->argv();
            } catch (InvalidArgumentException $e) {
                throw new DeploymentFailed("The command of the {$process->name} process is not valid: {$e->getMessage()}", 0, $e);
            }

            $base = "{$app->slug}-{$deployment->number}-{$process->name}";
            $replicas = $process->kind->mayReplicate() ? max(1, min(10, $process->replicas)) : 1;
            $containers = $replicas === 1 ? [$base] : array_map(fn (int $i) => "{$base}-{$i}", range(1, $replicas));

            if (strlen(end($containers)) > 63) {
                throw new DeploymentFailed("The name of the {$process->name} process is too long for this app: its container would be called ".end($containers).'. Give it a shorter name.');
            }

            $entries[] = [
                'name' => $process->name,
                'kind' => $process->kind->value,
                'overlap' => $process->overlap,
                'replicas' => $replicas,
                'command' => $process->effectiveCommand(),
                'memory_limit_mb' => $process->memory_limit_mb,
                'grace' => $process->stop_grace_seconds,
                'container' => $containers[0],
                'containers' => $containers,
                'started' => false,
            ];
        }

        $deployment->forceFill(['processes' => $entries === [] ? null : $entries])->save();

        return $entries;
    }

    /**
     * Start the processes that may run beside the old release, and make sure they stay up.
     *
     * @throws DeploymentFailed when one exits within a few seconds, with its last output
     */
    public function startOverlap(App $app, Deployment $deployment, DeployLogger $log): void
    {
        $started = [];

        foreach ($this->entries($deployment) as $i => $entry) {
            if ($entry['overlap'] !== AppProcess::OVERLAP) {
                continue;
            }

            foreach ($entry['containers'] as $container) {
                $log->step("Starting {$entry['name']} ({$container}).");
                $this->run($app, $deployment, $entry, $container);
                $started[] = $container;
            }

            $this->mark($deployment, $i, true);
        }

        if ($started === []) {
            return;
        }

        $seconds = max(0, (int) config('commypanel.deploy.process_settle_seconds'));

        if ($seconds > 0) {
            Sleep::for($seconds)->seconds();
        }

        $this->assertRunning($started, $log);
    }

    /**
     * Start the processes that must not run twice, after traffic has moved and the old ones stopped.
     * A failure here does not undo the release: it is told in the log, and the process can be started
     * again from the app page.
     */
    public function startSingletons(App $app, Deployment $deployment, DeployLogger $log): void
    {
        foreach ($this->entries($deployment) as $i => $entry) {
            if ($entry['overlap'] !== AppProcess::STOP_FIRST || $entry['started']) {
                continue;
            }

            try {
                foreach ($entry['containers'] as $container) {
                    $log->step("Starting {$entry['name']} ({$container}).");
                    $this->run($app, $deployment, $entry, $container);
                }

                $this->mark($deployment, $i, true);
            } catch (Throwable $e) {
                $log->line("Warning: could not start {$entry['name']}: {$e->getMessage()}");
                Log::warning('Could not start a process after go-live.', ['app' => $app->slug, 'process' => $entry['name'], 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Stop (not remove) the processes of a release that must not run twice, so the new release can start
     * its own, and so a rollback can start these again at once. Each gets its grace period to finish.
     */
    public function stopSingletons(?Deployment $release, DeployLogger $log): void
    {
        foreach ($this->entries($release) as $entry) {
            if ($entry['overlap'] !== AppProcess::STOP_FIRST || ! $entry['started']) {
                continue;
            }

            foreach ($entry['containers'] as $container) {
                $log->step("Stopping {$entry['name']} ({$container}).");

                try {
                    $this->host->stopContainer($container);
                } catch (HostOperationFailed $e) {
                    $log->line("Warning: could not stop {$container}: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Start again the processes of a release that were stopped (an automatic rollback goes back to it).
     */
    public function startSingletonsAgain(?Deployment $release, DeployLogger $log): void
    {
        foreach ($this->entries($release) as $entry) {
            if ($entry['overlap'] !== AppProcess::STOP_FIRST || ! $entry['started']) {
                continue;
            }

            foreach ($entry['containers'] as $container) {
                $log->step("Starting {$entry['name']} ({$container}) again.");

                try {
                    $this->host->startContainer($container);
                } catch (HostOperationFailed $e) {
                    $log->line("Warning: could not start {$container}: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * The containers of a live release a person can look at (logs, terminal, usage): the web one first,
     * then each process container that was made.
     *
     * @return list<array{container: string, label: string, process: string}>
     */
    public function lookable(?Deployment $release): array
    {
        if ($release === null || $release->container_name === null) {
            return [];
        }

        $out = [['container' => $release->container_name, 'label' => 'web', 'process' => 'web']];

        foreach ($this->entries($release) as $entry) {
            if (! $entry['started']) {
                continue;
            }

            foreach ($entry['containers'] as $i => $container) {
                $out[] = ['container' => $container, 'label' => $entry['replicas'] > 1 ? $entry['name'].' '.($i + 1) : $entry['name'], 'process' => $entry['name']];
            }
        }

        return $out;
    }

    /**
     * Every container name of a release's processes that was made.
     *
     * @return list<string>
     */
    public function containers(?Deployment $release): array
    {
        $names = [];

        foreach ($this->entries($release) as $entry) {
            if ($entry['started']) {
                array_push($names, ...$entry['containers']);
            }
        }

        return $names;
    }

    /**
     * Stop, start or restart every process container of a release (the app's own stop, start, restart).
     *
     * @param  'stop'|'start'|'restart'  $action
     *
     * @throws HostOperationFailed
     */
    public function control(?Deployment $release, string $action): void
    {
        foreach ($this->containers($release) as $container) {
            match ($action) {
                'stop' => $this->host->stopContainer($container),
                'start' => $this->host->startContainer($container),
                default => $this->host->restartContainer($container),
            };
        }
    }

    /**
     * Remove every process container of a release, stopped or not. Problems are returned, not thrown: this
     * is clean-up. `$planned` removes every container the release was planned to have, made or not (a failed
     * deployment).
     *
     * @return list<string>
     */
    public function removeAll(?Deployment $release, bool $stopFirst = false, bool $planned = false): array
    {
        $problems = [];

        foreach ($planned ? $this->plannedContainers($release) : $this->containers($release) as $container) {
            try {
                if ($stopFirst) {
                    $this->host->stopContainer($container);
                }

                $this->host->removeContainer($container);
            } catch (HostOperationFailed $e) {
                // Of the planned ones some were never made: not a problem worth telling.
                if (! $planned) {
                    $problems[] = "Container {$container}: {$e->getMessage()}";
                }
            }
        }

        return $problems;
    }

    /**
     * Every process container a release was ever planned to have, started or not (used when an app is deleted).
     *
     * @return list<string>
     */
    public function plannedContainers(?Deployment $release): array
    {
        $names = [];

        foreach ($this->entries($release) as $entry) {
            array_push($names, ...$entry['containers']);
        }

        return $names;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(?Deployment $release): array
    {
        return $release === null ? [] : array_values(array_filter((array) ($release->processes ?? []), 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function run(App $app, Deployment $deployment, array $entry, string $container): void
    {
        $argv = AppProcess::split((string) $entry['command']);

        $this->host->runContainer(new ContainerSpec(
            name: $container,
            image: (string) $deployment->image_tag,
            hostPort: 0,
            containerPort: 0,
            env: $this->environment($app),
            memoryLimitMb: $entry['memory_limit_mb'] ?? $app->memory_limit_mb,
            cpuLimit: $app->cpu_limit === null ? null : (float) $app->cpu_limit,
            volumes: $this->volumes->mounts($app),
            network: $app->networkName(),
            networks: $this->links->attachments($app, providing: false),
            command: $argv,
            stopTimeout: (int) ($entry['grace'] ?? 30),
            restart: 'unless-stopped',
            process: (string) $entry['name'],
        ));
    }

    private function mark(Deployment $deployment, int $index, bool $started): void
    {
        $entries = $this->entries($deployment);
        $entries[$index]['started'] = $started;
        $deployment->forceFill(['processes' => $entries])->save();
    }

    /**
     * @param  list<string>  $containers
     *
     * @throws DeploymentFailed
     */
    private function assertRunning(array $containers, DeployLogger $log): void
    {
        try {
            $state = [];

            foreach ($this->host->listContainers() as $info) {
                $state[$info->name] = $info->state;
            }
        } catch (HostOperationFailed) {
            return; // Docker cannot be asked: do not blame the release for that
        }

        foreach ($containers as $container) {
            if (($state[$container] ?? 'running') === 'running') {
                continue;
            }

            try {
                $log->line("--- output of {$container} ---");
                $log->line(rtrim($this->host->containerLogs($container, 30)));
                $log->line('------------------------');
            } catch (HostOperationFailed) {
                // The output is a nicety; the failure below is what matters.
            }

            throw new DeploymentFailed("The process {$container} stopped right after it started. Check its command and its output above; the new version was not started.");
        }
    }
}
