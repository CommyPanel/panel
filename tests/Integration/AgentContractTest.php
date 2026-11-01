<?php

use App\Host\Data\CertificateRequest;
use App\Host\Data\ContainerSpec;
use App\Host\Data\ImageBuildSpec;
use App\Host\Data\NginxSite;
use App\Host\Data\TaskSpec;
use App\Host\Drivers\AgentHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Runs the real Go agent in dry-run mode and exercises AgentHostDriver
 * against it. Skipped when the Go toolchain is not available.
 */
beforeEach(function () {
    $go = trim((string) shell_exec('command -v go 2>/dev/null'));
    if ($go === '') {
        $this->markTestSkipped('Go toolchain not available.');
    }

    $this->dir = sys_get_temp_dir().'/cp-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($this->dir);

    $build = Process::path(base_path('agent'))->run([$go, 'build', '-o', $this->dir.'/agent', './cmd/commypanel-agent']);
    expect($build->successful())->toBeTrue($build->errorOutput());

    $this->socket = $this->dir.'/agent.sock';
    $this->agent = Process::start([
        $this->dir.'/agent', '--dry-run',
        '--socket', $this->socket,
        '--data-dir', $this->dir.'/data',
        '--nginx-sites-dir', $this->dir.'/sites',
        '--allow-uid', (string) posix_geteuid(),
    ]);

    for ($i = 0; $i < 50 && ! file_exists($this->socket); $i++) {
        usleep(100_000);
    }
    expect(file_exists($this->socket))->toBeTrue('agent did not start');

    $this->driver = new AgentHostDriver($this->socket);
});

afterEach(function () {
    $this->agent?->signal(SIGTERM);
    $this->agent?->wait();
    File::deleteDirectory($this->dir);
});

it('reports system status', function () {
    $status = $this->driver->systemStatus();

    expect($status->diskFreeBytes)->toBeGreaterThan(0);
});

it('serves the server page data against the real agent', function () {
    $this->driver->applyNginxSite(new NginxSite('demo', ['demo.example.com'], 20001));

    $host = $this->driver->systemInfo();
    $docker = $this->driver->dockerInfo();
    $nginx = $this->driver->nginxStatus();

    expect($host->cpus)->toBeGreaterThan(0)
        ->and($host->diskTotalBytes)->toBeGreaterThan(0)
        ->and($host->services)->not->toBeEmpty()
        ->and($docker->available)->toBeFalse()
        ->and($this->driver->listImages())->toBe([])
        ->and($nginx->sites[0]['name'])->toBe('demo')
        ->and($nginx->sites[0]['domains'])->toBe(['demo.example.com'])
        ->and($this->driver->testNginx()->ok)->toBeTrue();

    $this->driver->reloadNginx();
});

it('reports resource usage from the real sampler', function () {
    // The sampler takes its first reading at start-up and the next two seconds later.
    for ($i = 0; $i < 40 && ! $this->driver->resourceUsage()->host['ready']; $i++) {
        usleep(100_000);
    }

    $usage = $this->driver->resourceUsage(history: true);

    expect($usage->intervalMs)->toBe(2000)
        ->and($usage->host['disk_total'])->toBeGreaterThan(0)
        ->and($usage->host['disk_used'])->toBeLessThanOrEqual($usage->host['disk_total'])
        ->and($usage->containers)->toBe([])
        ->and($usage->hostHistory)->toBeArray();
});

it('checks custom nginx directives and renders them into the site', function () {
    $this->driver->checkNginxCustom("# uploads\nclient_max_body_size 50m;\nlocation /api/ { proxy_pass \$commypanel_upstream; }");

    $site = new NginxSite('demo', ['demo.example.com'], 20001, custom: "client_max_body_size 50m;\nlocation /api/ { proxy_read_timeout 300s; }");
    $config = $this->driver->renderNginxSite($site);

    expect($config)->toContain('set $commypanel_upstream http://127.0.0.1:20001;')
        ->toContain("    client_max_body_size 50m;\n    location /api/ {\n        proxy_read_timeout 300s;\n    }")
        ->and($this->dir.'/sites/commypanel-demo.conf')->not->toBeFile();

    $this->driver->applyNginxSite($site);
    expect(file_get_contents($this->dir.'/sites/commypanel-demo.conf'))->toContain('client_max_body_size 50m;');
});

it('refuses dangerous custom nginx directives with the reason', function () {
    expect(fn () => $this->driver->checkNginxCustom("gzip on;\nalias /etc/;"))
        ->toThrow(HostOperationFailed::class, 'line 2');
    expect(fn () => $this->driver->applyNginxSite(new NginxSite('demo', ['demo.example.com'], 20001, custom: 'include /etc/passwd;')))
        ->toThrow(HostOperationFailed::class, 'not supported');
    expect($this->dir.'/sites/commypanel-demo.conf')->not->toBeFile();
});

it('applies and removes an nginx site', function () {
    $this->driver->applyNginxSite(new NginxSite('demo', ['demo.example.com'], 20001));

    $file = $this->dir.'/sites/commypanel-demo.conf';
    expect($file)->toBeFile()
        ->and(file_get_contents($file))->toContain('server_name demo.example.com;')
        ->toContain('proxy_pass http://127.0.0.1:20001;');

    $this->driver->removeNginxSite('demo');
    expect($file)->not->toBeFile();
});

it('surfaces agent validation errors', function () {
    // PHP's hostname filter accepts uppercase, the agent's allow-list does not.
    expect(fn () => $this->driver->applyNginxSite(new NginxSite('demo', ['Demo.Example.com'], 20001)))
        ->toThrow(HostOperationFailed::class, 'invalid domain');

    expect($this->dir.'/sites/commypanel-demo.conf')->not->toBeFile();
});

it('validates and accepts Docker operations in dry-run mode', function () {
    $this->driver->runContainer(new ContainerSpec('demo-1', 'commypanel/demo:r1', 20001, 8080));
    $this->driver->stopContainer('demo-1');
    $this->driver->removeContainer('demo-1');

    expect($this->driver->listContainers())->toBe([])
        ->and($this->driver->pruneDanglingImages())->toBe(0);
});

it('surfaces agent-side Docker validation errors', function () {
    // The PHP DTO accepts this image; the agent's allow-list does not.
    expect(fn () => $this->driver->runContainer(new ContainerSpec('demo-1', 'Not A Valid Image', 20001, 8080)))
        ->toThrow(HostOperationFailed::class, 'invalid image');
});

it('streams a dry-run build', function () {
    $lines = [];
    $this->driver->buildImage(
        new ImageBuildSpec('commypanel/demo:r1', $this->dir.'/data/builds/demo'),
        function (string $line) use (&$lines) {
            $lines[] = $line;
        },
    );

    expect($lines)->toBe(['[dry-run] would build commypanel/demo:r1']);
});

it('reports Docker as up, diagnoses and restarts it in dry-run mode', function () {
    expect($this->driver->systemStatus()->dockerAvailable)->toBeTrue();

    $diagnosis = $this->driver->dockerDiagnosis();
    expect($diagnosis->available)->toBeTrue()
        ->and($diagnosis->service)->toBe('unknown')
        ->and($diagnosis->journal)->toContain('dry-run');

    $this->driver->restartDocker();
});

it('starts and restarts containers in dry-run mode', function () {
    $this->driver->startContainer('demo-1');
    $this->driver->restartContainer('demo-1');

    expect(true)->toBeTrue();
});

it('streams a dry-run task and returns its exit code', function () {
    $lines = [];

    $code = $this->driver->runTask(
        new TaskSpec('demo-1-release', 'commypanel/demo:1', ['sh', '-c', 'migrate && seed'], ['A' => '1'], timeoutSeconds: 30),
        function (string $line) use (&$lines) {
            $lines[] = $line;
        },
    );

    expect($code)->toBe(0)
        ->and($lines)->toBe(['[dry-run] would run sh -c migrate && seed in commypanel/demo:1']);
});

it('surfaces agent-side task validation errors', function () {
    expect(fn () => $this->driver->runTask(new TaskSpec('demo-1-release', 'commypanel/demo:1', ['php', "a\0b"])))
        ->toThrow(HostOperationFailed::class, 'invalid command argument');
});

it('rejects a build context outside the builds directory', function () {
    expect(fn () => $this->driver->buildImage(new ImageBuildSpec('commypanel/demo:r1', '/etc')))
        ->toThrow(HostOperationFailed::class, 'outside the allowed directory');
});

it('issues, reads and removes a certificate in dry-run mode', function () {
    $info = $this->driver->issueCertificate(new CertificateRequest('shop', ['shop.example.com', 'www.shop.example.com']));

    expect($info->domains)->toBe(['shop.example.com', 'www.shop.example.com'])
        ->and($info->notAfter->getTimestamp())->toBeGreaterThan(time() + 80 * 86400);

    $key = $this->dir.'/data/certs/shop/privkey.pem';
    expect($key)->toBeFile()
        ->and(decoct(fileperms($key) & 0777))->toBe('600')
        ->and($this->driver->certificateInfo('shop')?->domains)->toBe(['shop.example.com', 'www.shop.example.com']);

    $this->driver->removeCertificate('shop');

    expect($this->driver->certificateInfo('shop'))->toBeNull()
        ->and($this->dir.'/data/certs/shop')->not->toBeDirectory();
});

it('renders an HTTPS nginx site that points at the stored certificate', function () {
    $this->driver->issueCertificate(new CertificateRequest('shop', ['shop.example.com']));
    $this->driver->applyNginxSite(new NginxSite('shop', ['shop.example.com'], 20001, 'shop'));

    $conf = file_get_contents($this->dir.'/sites/commypanel-shop.conf');

    expect($conf)->toContain('listen 443 ssl;')
        ->toContain('return 301 https://$host$request_uri;')
        ->toContain('ssl_certificate '.$this->dir.'/data/certs/shop/fullchain.pem;')
        ->toContain('ssl_certificate_key '.$this->dir.'/data/certs/shop/privkey.pem;');
});

it('rejects a certificate request the agent finds invalid', function () {
    expect(fn () => $this->driver->issueCertificate(new CertificateRequest('shop', ['Not A Domain'])))
        ->toThrow(HostOperationFailed::class, 'invalid domain');
});

it('installs the catch-all port 80 site for ACME challenges at start-up', function () {
    expect($this->dir.'/sites/00-commypanel-default.conf')->toBeFile()
        ->and(file_get_contents($this->dir.'/sites/00-commypanel-default.conf'))->toContain('default_server');
});

it('is refused when the caller is not on the allow-list', function () {
    $other = $this->dir.'/other.sock';
    $agent = Process::start([
        $this->dir.'/agent', '--dry-run', '--socket', $other,
        '--data-dir', $this->dir.'/data', '--nginx-sites-dir', $this->dir.'/sites',
        '--allow-uid', '1',
    ]);
    for ($i = 0; $i < 50 && ! file_exists($other); $i++) {
        usleep(100_000);
    }

    try {
        // On Linux the UID check denies us; on macOS dry-run accepts all local callers.
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Peer credentials are only checked on Linux.');
        }

        expect(fn () => (new AgentHostDriver($other))->systemStatus())
            ->toThrow(HostOperationFailed::class, 'forbidden');
    } finally {
        $agent->signal(SIGTERM);
        $agent->wait();
    }
});
