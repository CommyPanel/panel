<?php

namespace App\Host\Drivers;

use App\Host\Data\CertificateInfo;
use App\Host\Data\CertificateRequest;
use App\Host\Data\ContainerInfo;
use App\Host\Data\ContainerSpec;
use App\Host\Data\ContainerUsage;
use App\Host\Data\DockerDiagnosis;
use App\Host\Data\DockerInfo;
use App\Host\Data\HostInfo;
use App\Host\Data\ImageBuildSpec;
use App\Host\Data\ImageInfo;
use App\Host\Data\NginxSite;
use App\Host\Data\NginxStatus;
use App\Host\Data\NginxTestResult;
use App\Host\Data\ResourceName;
use App\Host\Data\ResourceUsage;
use App\Host\Data\SystemStatus;
use App\Host\Data\TaskSpec;
use App\Host\Data\VolumeEntry;
use App\Host\Data\VolumeFilePreview;
use App\Host\Data\VolumeListing;
use App\Host\Data\VolumeUsage;
use App\Host\Exceptions\DockerUnavailable;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Records host operations instead of performing them.
 */
class FakeHostDriver implements HostDriver
{
    /** @var list<array{action: string, args: array<string, mixed>}> */
    private array $actions = [];

    private ?string $failOn = null;

    /** @var array<string, NginxSite> */
    private array $sites = [];

    /** @var list<string> */
    private array $images = [];

    /** @var array<string, ContainerSpec> */
    private array $containers = [];

    /** @var array<string, CertificateInfo> */
    private array $certificates = [];

    /** @var array<string, true> names of containers that are stopped */
    private array $stopped = [];

    private int $taskExitCode = 0;

    private bool $dockerDown = false;

    /** @var array<string, array<string, string>> "app/volume" => relative path => content */
    private array $volumeFiles = [];

    /** Actions that need the Docker daemon; they fail while it is down. */
    private const DOCKER_ACTIONS = [
        'buildImage', 'runContainer', 'runTask', 'stopContainer', 'startContainer', 'restartContainer',
        'removeContainer', 'containerLogs', 'pruneDanglingImages', 'removeImage',
    ];

    public function systemStatus(): SystemStatus
    {
        return new SystemStatus(dockerAvailable: ! $this->dockerDown, nginxAvailable: true, diskFreeBytes: 50 * 1024 ** 3);
    }

    public function systemInfo(): HostInfo
    {
        return new HostInfo(
            hostname: 'fake-host', os: 'Fake Linux 1.0', kernel: '6.8.0-fake', arch: 'amd64', cpus: 4,
            uptimeSeconds: 3 * 86400, load: [0.4, 0.3, 0.2],
            memTotalBytes: 4 * 1024 ** 3, memAvailableBytes: 3 * 1024 ** 3,
            diskTotalBytes: 100 * 1024 ** 3, diskFreeBytes: 50 * 1024 ** 3,
            services: array_map(fn (string $name) => ['name' => $name, 'state' => 'active'], [
                'nginx', 'docker', 'commypanel-agent', 'commypanel-queue@1', 'commypanel-queue@2', 'commypanel-scheduler', 'php8.4-fpm',
            ]),
            agentVersion: 'fake',
        );
    }

    public function dockerInfo(): DockerInfo
    {
        if ($this->dockerDown) {
            return new DockerInfo(available: false, error: 'docker is not available');
        }

        $running = count($this->containers);

        return new DockerInfo(
            available: true, serverVersion: '27.0.0-fake', apiVersion: '1.46', os: 'linux', arch: 'amd64',
            kernelVersion: '6.8.0-fake', storageDriver: 'overlay2', cgroupDriver: 'systemd',
            containers: $running, running: $running, images: count($this->images), cpus: 4, memTotalBytes: 4 * 1024 ** 3,
        );
    }

    /**
     * Smoothly moving made-up readings, so the charts have something to show
     * during development. Containers are the ones this fake was told to run.
     */
    public function resourceUsage(bool $history = false): ResourceUsage
    {
        $interval = 2000;
        $now = time() * 1000;
        $wave = fn (int $t, float $period, float $phase = 0.0): float => (sin($t / 1000 / $period + $phase) + 1) / 2;
        $hostAt = fn (int $t) => [
            't' => $t,
            'cpu' => round(8 + 40 * $wave($t, 40) + 6 * $wave($t, 7, 2), 1),
            'mem' => (int) (1.1 * 1024 ** 3 + 0.3 * 1024 ** 3 * $wave($t, 90, 1)),
            'rx' => round(40_000 + 300_000 * $wave($t, 25, 3), 0),
            'tx' => round(15_000 + 120_000 * $wave($t, 31, 5), 0),
        ];
        $containerAt = fn (int $t, int $seed) => [
            't' => $t,
            'cpu' => round(2 + 30 * $wave($t, 20 + $seed * 3, $seed), 1),
            'mem' => (int) ((90 + 40 * $wave($t, 60, $seed)) * 1024 ** 2),
            'rx' => round(2_000 + 40_000 * $wave($t, 17, $seed), 0),
            'tx' => round(1_000 + 25_000 * $wave($t, 23, $seed + 1), 0),
        ];
        $series = fn (callable $at) => array_map($at, array_map(fn (int $i) => $now - (149 - $i) * $interval, range(0, 149)));

        $containers = [];
        $seed = 0;

        foreach ($this->containers as $name => $spec) {
            if (isset($this->stopped[$name])) {
                continue;
            }

            $seed++;
            $point = $containerAt($now, $seed);
            $containers[] = new ContainerUsage(
                name: $name, t: $now, cpuPercent: $point['cpu'], memUsed: $point['mem'],
                memLimit: ($spec->memoryLimitMb ?? 512) * 1024 ** 2, netRxBps: $point['rx'], netTxBps: $point['tx'],
                history: $history ? $series(fn (int $t) => $containerAt($t, $seed)) : [],
            );
        }

        $point = $hostAt($now);

        return new ResourceUsage(
            intervalMs: $interval,
            now: $now,
            host: [
                'ready' => true, 'cpu_percent' => $point['cpu'], 'cpus' => 4,
                'mem_total' => 4 * 1024 ** 3, 'mem_used' => $point['mem'],
                'disk_total' => 100 * 1024 ** 3, 'disk_used' => 50 * 1024 ** 3,
                'net_rx_bps' => $point['rx'], 'net_tx_bps' => $point['tx'],
            ],
            hostHistory: $history ? $series($hostAt) : [],
            containers: $containers,
        );
    }

    public function dockerDiagnosis(): DockerDiagnosis
    {
        return $this->dockerDown
            ? new DockerDiagnosis(false, 'docker is not available', 'failed', 'active', "[fake] dockerd: failed to start daemon\n[fake] docker.service: Failed with result 'exit-code'.")
            : new DockerDiagnosis(true, null, 'active', 'active', '[fake] dockerd: API listen on /run/docker.sock');
    }

    public function restartDocker(): void
    {
        $this->record('restartDocker', []);
        $this->dockerDown = false;
    }

    /**
     * Simulate a stopped or broken Docker daemon until restartDocker().
     */
    public function dockerGoesDown(): static
    {
        $this->dockerDown = true;

        return $this;
    }

    public function listImages(): array
    {
        return array_map(fn (string $tag) => new ImageInfo([$tag], 120 * 1024 ** 2, time() - 3600), $this->images);
    }

    public function nginxStatus(): NginxStatus
    {
        return new NginxStatus(
            available: true, running: true, version: '1.26.0-fake', configOk: true, configOutput: 'syntax is ok',
            sites: array_values(array_map(fn (NginxSite $site) => [
                'name' => $site->name, 'domains' => $site->domains, 'https' => $site->certificate !== null, 'modified' => time(),
            ], $this->sites)),
        );
    }

    public function testNginx(): NginxTestResult
    {
        $this->record('testNginx', []);

        return new NginxTestResult(true, 'syntax is ok');
    }

    public function reloadNginx(): void
    {
        $this->record('reloadNginx', []);
    }

    public function applyNginxSite(NginxSite $site): void
    {
        $this->record('applyNginxSite', ['site' => $site]);
        $this->sites[$site->name] = $site;
    }

    public function renderNginxSite(NginxSite $site): string
    {
        $this->record('renderNginxSite', ['site' => $site]);

        return "# [fake] nginx site {$site->name} for ".implode(' ', $site->domains)." -> 127.0.0.1:{$site->upstreamPort}\n"
            .($site->custom === null ? '' : "# [fake] custom directives:\n{$site->custom}\n");
    }

    public function checkNginxCustom(string $custom): void
    {
        $this->record('checkNginxCustom', ['custom' => $custom]);
    }

    public function removeNginxSite(string $name): void
    {
        ResourceName::assertValid($name);
        $this->record('removeNginxSite', ['name' => $name]);
        unset($this->sites[$name]);
    }

    public function buildImage(ImageBuildSpec $spec, ?callable $onOutput = null): void
    {
        $this->record('buildImage', ['spec' => $spec]);
        $this->images[] = $spec->tag;

        if ($onOutput !== null) {
            $onOutput("[fake] building {$spec->tag}");
        }
    }

    public function runContainer(ContainerSpec $spec): void
    {
        $this->record('runContainer', ['spec' => $spec]);
        $this->containers[$spec->name] = $spec;
    }

    public function stopContainer(string $name): void
    {
        ResourceName::assertValid($name);
        $this->record('stopContainer', ['name' => $name]);
        $this->stopped[$name] = true;
    }

    public function startContainer(string $name): void
    {
        ResourceName::assertValid($name);
        $this->record('startContainer', ['name' => $name]);

        if (! isset($this->containers[$name])) {
            throw new HostOperationFailed("Agent error: container \"{$name}\" does not exist");
        }

        unset($this->stopped[$name]);
    }

    public function restartContainer(string $name): void
    {
        ResourceName::assertValid($name);
        $this->record('restartContainer', ['name' => $name]);

        if (! isset($this->containers[$name])) {
            throw new HostOperationFailed("Agent error: container \"{$name}\" does not exist");
        }

        unset($this->stopped[$name]);
    }

    public function runTask(TaskSpec $spec, ?callable $onOutput = null): int
    {
        $this->record('runTask', ['spec' => $spec]);

        if ($onOutput !== null) {
            $onOutput('[fake] running '.implode(' ', $spec->command));
        }

        return $this->taskExitCode;
    }

    public function removeContainer(string $name): void
    {
        ResourceName::assertValid($name);
        $this->record('removeContainer', ['name' => $name]);
        unset($this->containers[$name], $this->stopped[$name]);
    }

    public function issueCertificate(CertificateRequest $request): CertificateInfo
    {
        $this->record('issueCertificate', ['request' => $request]);

        return $this->certificates[$request->name] = new CertificateInfo(
            $request->name,
            $request->domains,
            new DateTimeImmutable('-1 minute'),
            new DateTimeImmutable('+90 days'),
            $request->staging ? '(STAGING) Fake CA' : 'Fake CA',
        );
    }

    public function certificateInfo(string $name): ?CertificateInfo
    {
        return $this->certificates[$name] ?? null;
    }

    public function removeCertificate(string $name): void
    {
        ResourceName::assertValid($name);
        $this->record('removeCertificate', ['name' => $name]);
        unset($this->certificates[$name]);
    }

    /**
     * @return array<string, CertificateInfo>
     */
    public function certificates(): array
    {
        return $this->certificates;
    }

    public function removeImage(string $tag): void
    {
        $this->record('removeImage', ['tag' => $tag]);
        $this->images = array_values(array_filter($this->images, fn (string $t) => $t !== $tag));
    }

    public function listContainers(): array
    {
        return array_values(array_map(
            fn (ContainerSpec $spec) => isset($this->stopped[$spec->name])
                ? new ContainerInfo($spec->name, $spec->image, 'exited', 'Exited (0) (fake)')
                : new ContainerInfo($spec->name, $spec->image, 'running', 'Up (fake)'),
            $this->containers,
        ));
    }

    public function containerLogs(string $name, int $tail = 200): string
    {
        ResourceName::assertValid($name);
        $this->record('containerLogs', ['name' => $name, 'tail' => $tail]);

        return "[fake] logs for {$name}\n";
    }

    public function removeVolumes(string $app, ?string $name = null): void
    {
        ResourceName::assertValid($app);
        $this->record('removeVolumes', ['app' => $app, 'name' => $name]);
    }

    /**
     * Put a file into a fake volume (tests): $path is relative to the volume.
     */
    public function putVolumeFile(string $app, string $name, string $path, string $content): static
    {
        $this->volumeFiles["{$app}/{$name}"][trim($path, '/')] = $content;

        return $this;
    }

    public function listVolumes(string $app): array
    {
        ResourceName::assertValid($app);
        $this->record('listVolumes', ['app' => $app]);

        $out = [];

        foreach ($this->volumeFiles as $key => $files) {
            [$owner, $name] = explode('/', $key, 2);

            if ($owner === $app) {
                $out[] = new VolumeUsage($name, array_sum(array_map('strlen', $files)), count($files));
            }
        }

        usort($out, fn (VolumeUsage $a, VolumeUsage $b) => $a->name <=> $b->name);

        return $out;
    }

    public function browseVolume(string $app, string $name, string $path = ''): VolumeListing
    {
        ResourceName::assertValid($app);
        $this->record('browseVolume', ['app' => $app, 'name' => $name, 'path' => $path]);

        $files = $this->volumeFiles["{$app}/{$name}"] ?? throw new HostOperationFailed('Agent error: that does not exist');
        $prefix = trim($path, '/') === '' ? '' : trim($path, '/').'/';
        $entries = [];

        foreach ($files as $file => $content) {
            if (! str_starts_with($file, $prefix)) {
                continue;
            }

            $rest = substr($file, strlen($prefix));
            $first = explode('/', $rest, 2);

            if (count($first) === 2) {
                $entries[$first[0]] = new VolumeEntry($first[0], 'dir', 0, 1_700_000_000);
            } else {
                $entries[$first[0]] = new VolumeEntry($first[0], 'file', strlen($content), 1_700_000_000);
            }
        }

        if ($entries === [] && $prefix !== '') {
            throw new HostOperationFailed('Agent error: that does not exist');
        }

        uasort($entries, fn (VolumeEntry $a, VolumeEntry $b) => [$a->kind !== 'dir', strtolower($a->name)] <=> [$b->kind !== 'dir', strtolower($b->name)]);

        return new VolumeListing(array_values($entries));
    }

    public function previewVolumeFile(string $app, string $name, string $path): VolumeFilePreview
    {
        $content = $this->readVolumeFile($app, $name, $path);
        $this->record('previewVolumeFile', ['app' => $app, 'name' => $name, 'path' => $path]);

        if (str_contains($content, "\0")) {
            return new VolumeFilePreview(strlen($content), '', binary: true);
        }

        return new VolumeFilePreview(strlen($content), substr($content, 0, 262144), truncated: strlen($content) > 262144);
    }

    public function readVolumeFile(string $app, string $name, string $path): string
    {
        ResourceName::assertValid($app);
        $this->record('readVolumeFile', ['app' => $app, 'name' => $name, 'path' => $path]);

        return $this->volumeFiles["{$app}/{$name}"][trim($path, '/')] ?? throw new HostOperationFailed('Agent error: that does not exist');
    }

    public function pruneDanglingImages(): int
    {
        $this->record('pruneDanglingImages', []);

        return 0;
    }

    /**
     * The exit code the next tasks report.
     */
    public function taskExitsWith(int $code): static
    {
        $this->taskExitCode = $code;

        return $this;
    }

    /**
     * Make the next call of the given action throw, to test failure paths.
     */
    public function failOn(string $action): static
    {
        $this->failOn = $action;

        return $this;
    }

    /**
     * @return list<array{action: string, args: array<string, mixed>}>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @return list<string>
     */
    public function actionNames(): array
    {
        return array_column($this->actions, 'action');
    }

    /**
     * @return array<string, NginxSite>
     */
    public function sites(): array
    {
        return $this->sites;
    }

    /**
     * @return array<string, ContainerSpec>
     */
    public function containers(): array
    {
        return $this->containers;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function record(string $action, array $args): void
    {
        if ($this->dockerDown && in_array($action, self::DOCKER_ACTIONS, true)) {
            throw new DockerUnavailable('Agent error: docker is not available');
        }

        if ($this->failOn === $action) {
            $this->failOn = null;

            throw new HostOperationFailed("Fake failure on [{$action}].");
        }

        $this->actions[] = ['action' => $action, 'args' => $args];

        Log::debug("[fake host] {$action}");
    }
}
