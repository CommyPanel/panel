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
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Performs host operations through commypanel-agent over its Unix socket.
 */
class AgentHostDriver implements HostDriver
{
    public function __construct(private readonly string $socketPath) {}

    public function systemStatus(): SystemStatus
    {
        // Every page asks for this (see HostHealth), so a hung agent must not stall them.
        $data = $this->handle($this->request(fn () => $this->client(timeout: 6)->get('/v1/status')));

        return new SystemStatus(
            dockerAvailable: (bool) ($data['docker_available'] ?? false),
            nginxAvailable: (bool) ($data['nginx_available'] ?? false),
            diskFreeBytes: (int) ($data['disk_free_bytes'] ?? 0),
        );
    }

    public function systemInfo(): HostInfo
    {
        $data = $this->send('get', '/v1/system/info');
        $info = $data['info'] ?? [];
        $load = array_values($info['load'] ?? []);

        return new HostInfo(
            hostname: (string) ($info['hostname'] ?? ''),
            os: (string) ($info['os'] ?? ''),
            kernel: (string) ($info['kernel'] ?? ''),
            arch: (string) ($info['arch'] ?? ''),
            cpus: (int) ($info['cpus'] ?? 0),
            uptimeSeconds: (int) ($info['uptime_seconds'] ?? 0),
            load: [(float) ($load[0] ?? 0), (float) ($load[1] ?? 0), (float) ($load[2] ?? 0)],
            memTotalBytes: (int) ($info['mem_total_bytes'] ?? 0),
            memAvailableBytes: (int) ($info['mem_available_bytes'] ?? 0),
            diskTotalBytes: (int) ($info['disk_total_bytes'] ?? 0),
            diskFreeBytes: (int) ($info['disk_free_bytes'] ?? 0),
            services: array_values(array_map(
                fn (array $s) => ['name' => (string) ($s['name'] ?? ''), 'state' => (string) ($s['state'] ?? 'unknown')],
                $info['services'] ?? [],
            )),
            agentVersion: (string) ($data['version'] ?? ''),
        );
    }

    public function resourceUsage(bool $history = false): ResourceUsage
    {
        $d = $this->send('get', '/v1/system/usage'.($history ? '?history=1' : ''));
        $h = $d['host'] ?? [];

        return new ResourceUsage(
            intervalMs: (int) ($d['interval_ms'] ?? 2000),
            now: (int) ($d['now'] ?? 0),
            host: [
                'ready' => (bool) ($h['ready'] ?? false),
                'cpu_percent' => (float) ($h['cpu_percent'] ?? 0),
                'cpus' => (int) ($h['cpus'] ?? 0),
                'mem_total' => (int) ($h['mem_total'] ?? 0),
                'mem_used' => (int) ($h['mem_used'] ?? 0),
                'disk_total' => (int) ($h['disk_total'] ?? 0),
                'disk_used' => (int) ($h['disk_used'] ?? 0),
                'net_rx_bps' => (float) ($h['net_rx_bps'] ?? 0),
                'net_tx_bps' => (float) ($h['net_tx_bps'] ?? 0),
            ],
            hostHistory: $this->samples($d['host_history'] ?? []),
            containers: array_values(array_map(fn (array $c) => new ContainerUsage(
                name: (string) ($c['name'] ?? ''),
                t: (int) ($c['t'] ?? 0),
                cpuPercent: (float) ($c['cpu_percent'] ?? 0),
                memUsed: (int) ($c['mem_used'] ?? 0),
                memLimit: (int) ($c['mem_limit'] ?? 0),
                netRxBps: (float) ($c['net_rx_bps'] ?? 0),
                netTxBps: (float) ($c['net_tx_bps'] ?? 0),
                history: $this->samples($c['history'] ?? []),
            ), $d['containers'] ?? [])),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return list<array{t: int, cpu: float, mem: int, rx: float, tx: float}>
     */
    private function samples(array $samples): array
    {
        return array_values(array_map(fn (array $s) => [
            't' => (int) ($s['t'] ?? 0),
            'cpu' => (float) ($s['cpu'] ?? 0),
            'mem' => (int) ($s['mem'] ?? 0),
            'rx' => (float) ($s['rx'] ?? 0),
            'tx' => (float) ($s['tx'] ?? 0),
        ], $samples));
    }

    public function dockerInfo(): DockerInfo
    {
        $d = $this->send('get', '/v1/docker/info');

        return new DockerInfo(
            available: (bool) ($d['available'] ?? false),
            error: isset($d['error']) ? (string) $d['error'] : null,
            serverVersion: (string) ($d['server_version'] ?? ''),
            apiVersion: (string) ($d['api_version'] ?? ''),
            os: (string) ($d['os'] ?? ''),
            arch: (string) ($d['arch'] ?? ''),
            kernelVersion: (string) ($d['kernel_version'] ?? ''),
            storageDriver: (string) ($d['storage_driver'] ?? ''),
            cgroupDriver: (string) ($d['cgroup_driver'] ?? ''),
            containers: (int) ($d['containers'] ?? 0),
            running: (int) ($d['running'] ?? 0),
            paused: (int) ($d['paused'] ?? 0),
            stopped: (int) ($d['stopped'] ?? 0),
            images: (int) ($d['images'] ?? 0),
            cpus: (int) ($d['cpus'] ?? 0),
            memTotalBytes: (int) ($d['mem_total_bytes'] ?? 0),
        );
    }

    public function dockerDiagnosis(): DockerDiagnosis
    {
        $d = $this->send('get', '/v1/docker/diagnose');

        return new DockerDiagnosis(
            available: (bool) ($d['available'] ?? false),
            error: isset($d['error']) ? (string) $d['error'] : null,
            service: (string) ($d['service'] ?? 'unknown'),
            socket: (string) ($d['socket'] ?? 'unknown'),
            journal: (string) ($d['journal'] ?? ''),
        );
    }

    public function restartDocker(): void
    {
        $this->handle($this->request(fn () => $this->client(timeout: 150)->post('/v1/docker/restart')));
    }

    public function listImages(): array
    {
        $data = $this->send('get', '/v1/docker/images');

        return array_values(array_map(fn (array $i) => new ImageInfo(
            tags: array_values(array_map('strval', $i['tags'] ?? [])),
            sizeBytes: (int) ($i['size_bytes'] ?? 0),
            created: (int) ($i['created'] ?? 0),
        ), $data['images'] ?? []));
    }

    public function nginxStatus(): NginxStatus
    {
        $d = $this->send('get', '/v1/nginx/status');

        return new NginxStatus(
            available: (bool) ($d['available'] ?? false),
            running: (bool) ($d['running'] ?? false),
            version: (string) ($d['version'] ?? ''),
            configOk: (bool) ($d['config_ok'] ?? false),
            configOutput: (string) ($d['config_output'] ?? ''),
            sites: array_values(array_map(fn (array $s) => [
                'name' => (string) ($s['name'] ?? ''),
                'domains' => array_values(array_map('strval', $s['domains'] ?? [])),
                'https' => (bool) ($s['https'] ?? false),
                'modified' => (int) ($s['modified'] ?? 0),
            ], $d['sites'] ?? [])),
        );
    }

    public function testNginx(): NginxTestResult
    {
        $d = $this->send('post', '/v1/nginx/test');

        return new NginxTestResult((bool) ($d['ok'] ?? false), (string) ($d['output'] ?? ''));
    }

    public function reloadNginx(): void
    {
        $this->send('post', '/v1/nginx/reload');
    }

    public function applyNginxSite(NginxSite $site): void
    {
        $this->send('put', '/v1/nginx/sites', $this->siteBody($site));
    }

    public function renderNginxSite(NginxSite $site): string
    {
        return (string) ($this->send('post', '/v1/nginx/render', $this->siteBody($site))['config'] ?? '');
    }

    public function checkNginxCustom(string $custom): void
    {
        $this->send('post', '/v1/nginx/custom/check', ['custom' => $custom]);
    }

    /**
     * @return array<string, mixed>
     */
    private function siteBody(NginxSite $site): array
    {
        return array_filter([
            'name' => $site->name,
            'domains' => $site->domains,
            'upstream_port' => $site->upstreamPort,
            'certificate' => $site->certificate,
            'redirects' => $site->redirects === [] ? null : array_map(
                fn (string $from, string $to) => ['from' => $from, 'to' => $to],
                array_keys($site->redirects),
                array_values($site->redirects),
            ),
            'custom' => $site->custom === '' ? null : $site->custom,
        ], fn ($value) => $value !== null);
    }

    public function removeNginxSite(string $name): void
    {
        ResourceName::assertValid($name);

        $this->send('delete', '/v1/nginx/sites/'.$name);
    }

    public function buildImage(ImageBuildSpec $spec, ?callable $onOutput = null): void
    {
        $this->stream('/v1/docker/images/build', [
            'tag' => $spec->tag,
            'context_path' => $spec->contextPath,
            'dockerfile' => $spec->dockerfile,
        ], 3600, $onOutput, 'The build output ended unexpectedly.');
    }

    public function runTask(TaskSpec $spec, ?callable $onOutput = null): int
    {
        $stream = $this->stream('/v1/docker/tasks', array_filter([
            'name' => $spec->name,
            'image' => $spec->image,
            'command' => $spec->command,
            'env' => $spec->env,
            'volumes' => array_map(fn ($volume) => [
                'source' => $volume->source,
                'target' => $volume->target,
                'read_only' => $volume->readOnly,
            ], $spec->volumes),
            'memory_mb' => $spec->memoryLimitMb,
            'cpus' => $spec->cpuLimit,
            'network' => $spec->network,
            'timeout_seconds' => $spec->timeoutSeconds,
        ], fn ($value) => $value !== null && $value !== []), $spec->timeoutSeconds + 60, $onOutput, 'The command output ended unexpectedly.');

        return (int) $stream->exitCode;
    }

    /**
     * POST to an endpoint that answers with newline-delimited JSON, passing
     * log lines on as they arrive.
     *
     * @param  array<string, mixed>  $body
     * @param  (callable(string): void)|null  $onOutput
     */
    private function stream(string $path, array $body, int $timeout, ?callable $onOutput, string $cutOff): BuildStream
    {
        $stream = new BuildStream($onOutput);

        // Guzzle's `stream` option would switch to a handler that ignores the
        // Unix socket, so the response is written into a sink that feeds the parser.
        $sink = FnStream::decorate(Utils::streamFor(''), [
            'write' => function (string $chunk) use ($stream): int {
                $stream->write($chunk);

                return strlen($chunk);
            },
        ]);

        $response = $this->request(fn () => $this->client(timeout: $timeout)
            ->withOptions(['sink' => $sink])
            ->post($path, $body));

        if (! $stream->received) {
            $stream->write($response->body());
        }

        $stream->finish();

        if ($response->failed()) {
            $this->throwFor($response->status(), $stream->error);
        }

        if ($stream->error !== null) {
            throw new HostOperationFailed($stream->error);
        }

        if (! $stream->finished) {
            throw new HostOperationFailed($cutOff);
        }

        return $stream;
    }

    public function runContainer(ContainerSpec $spec): void
    {
        $this->send('post', '/v1/docker/containers', array_filter([
            'name' => $spec->name,
            'image' => $spec->image,
            'host_port' => $spec->hostPort,
            'container_port' => $spec->containerPort,
            'env' => $spec->env,
            'volumes' => array_map(fn ($volume) => [
                'source' => $volume->source,
                'target' => $volume->target,
                'read_only' => $volume->readOnly,
            ], $spec->volumes),
            'memory_mb' => $spec->memoryLimitMb,
            'cpus' => $spec->cpuLimit,
            'network' => $spec->network,
        ], fn ($value) => $value !== null && $value !== []));
    }

    public function stopContainer(string $name): void
    {
        ResourceName::assertValid($name);

        $this->send('post', "/v1/docker/containers/{$name}/stop");
    }

    public function startContainer(string $name): void
    {
        ResourceName::assertValid($name);

        $this->send('post', "/v1/docker/containers/{$name}/start");
    }

    public function restartContainer(string $name): void
    {
        ResourceName::assertValid($name);

        $this->send('post', "/v1/docker/containers/{$name}/restart");
    }

    public function removeContainer(string $name): void
    {
        ResourceName::assertValid($name);

        $this->send('delete', "/v1/docker/containers/{$name}");
    }

    public function issueCertificate(CertificateRequest $request): CertificateInfo
    {
        // Issuing waits for Let's Encrypt to validate every domain.
        $response = $this->request(fn () => $this->client(timeout: 400)->post('/v1/certificates/issue', array_filter([
            'name' => $request->name,
            'domains' => $request->domains,
            'email' => $request->email,
            'staging' => $request->staging,
            'agree_tos' => true,
        ], fn ($value) => $value !== null)));

        return $this->certificateFrom($this->handle($response));
    }

    public function certificateInfo(string $name): ?CertificateInfo
    {
        ResourceName::assertValid($name);

        $response = $this->request(fn () => $this->client()->get("/v1/certificates/{$name}"));

        if ($response->status() === 404) {
            return null;
        }

        return $this->certificateFrom($this->handle($response));
    }

    public function removeCertificate(string $name): void
    {
        ResourceName::assertValid($name);

        $this->send('delete', "/v1/certificates/{$name}");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function certificateFrom(array $data): CertificateInfo
    {
        return new CertificateInfo(
            name: (string) ($data['name'] ?? ''),
            domains: array_values(array_map('strval', $data['domains'] ?? [])),
            notBefore: new DateTimeImmutable((string) ($data['not_before'] ?? 'now')),
            notAfter: new DateTimeImmutable((string) ($data['not_after'] ?? 'now')),
            issuer: (string) ($data['issuer'] ?? ''),
        );
    }

    public function removeImage(string $tag): void
    {
        $this->send('post', '/v1/docker/images/remove', ['tag' => $tag]);
    }

    public function listContainers(): array
    {
        $data = $this->send('get', '/v1/docker/containers');

        return array_values(array_map(fn (array $c) => new ContainerInfo(
            name: (string) ($c['name'] ?? ''),
            image: (string) ($c['image'] ?? ''),
            state: (string) ($c['state'] ?? ''),
            status: (string) ($c['status'] ?? ''),
            created: (int) ($c['created'] ?? 0),
        ), $data['containers'] ?? []));
    }

    public function containerLogs(string $name, int $tail = 200): string
    {
        ResourceName::assertValid($name);

        $data = $this->send('get', "/v1/docker/containers/{$name}/logs?tail={$tail}");

        return (string) ($data['logs'] ?? '');
    }

    public function removeVolumes(string $app, ?string $name = null): void
    {
        ResourceName::assertValid($app);

        $this->send('post', '/v1/volumes/remove', ['app' => $app, 'name' => $name ?? '']);
    }

    public function listVolumes(string $app): array
    {
        ResourceName::assertValid($app);

        $data = $this->send('get', "/v1/volumes/{$app}");

        return array_values(array_map(fn (array $v) => new VolumeUsage(
            name: (string) ($v['name'] ?? ''),
            bytes: (int) ($v['bytes'] ?? 0),
            files: (int) ($v['files'] ?? 0),
            partial: (bool) ($v['partial'] ?? false),
        ), $data['volumes'] ?? []));
    }

    public function browseVolume(string $app, string $name, string $path = ''): VolumeListing
    {
        $data = $this->send('get', $this->volumeUrl($app, $name, 'dir'), $path === '' ? [] : ['path' => $path]);

        return new VolumeListing(
            array_values(array_map(fn (array $e) => new VolumeEntry(
                name: (string) ($e['name'] ?? ''),
                kind: (string) ($e['kind'] ?? 'other'),
                size: (int) ($e['size'] ?? 0),
                modified: (int) ($e['modified'] ?? 0),
            ), $data['entries'] ?? [])),
            (bool) ($data['truncated'] ?? false),
        );
    }

    public function previewVolumeFile(string $app, string $name, string $path): VolumeFilePreview
    {
        $data = $this->send('get', $this->volumeUrl($app, $name, 'file'), ['path' => $path]);

        return new VolumeFilePreview(
            size: (int) ($data['size'] ?? 0),
            content: (string) ($data['content'] ?? ''),
            binary: (bool) ($data['binary'] ?? false),
            truncated: (bool) ($data['truncated'] ?? false),
        );
    }

    public function readVolumeFile(string $app, string $name, string $path): string
    {
        $response = $this->request(fn () => $this->client(120)->get($this->volumeUrl($app, $name, 'download'), ['path' => $path]));

        if ($response->failed()) {
            $this->throwFor($response->status(), $response->json('error'));
        }

        return $response->body();
    }

    private function volumeUrl(string $app, string $name, string $action): string
    {
        ResourceName::assertValid($app);

        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid volume name [{$name}].");
        }

        return "/v1/volumes/{$app}/{$name}/{$action}";
    }

    public function pruneDanglingImages(): int
    {
        $data = $this->send('post', '/v1/docker/images/prune');

        return (int) ($data['space_reclaimed'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $body = []): array
    {
        $response = $this->request(fn () => $this->client()->{$method}($path, $body === [] ? null : $body));

        return $this->handle($response);
    }

    private function request(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            throw new HostOperationFailed("The CommyPanel agent is not reachable at {$this->socketPath}.", 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(Response $response): array
    {
        if ($response->failed()) {
            $this->throwFor($response->status(), $response->json('error'));
        }

        return $response->json() ?? [];
    }

    private function throwFor(int $status, ?string $error): never
    {
        $message = 'Agent error: '.($error ?? "HTTP {$status}");

        throw $status === 503 ? new DockerUnavailable($message) : new HostOperationFailed($message);
    }

    private function client(int $timeout = 60): PendingRequest
    {
        return Http::baseUrl('http://agent')
            ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $this->socketPath]])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout($timeout);
    }
}
