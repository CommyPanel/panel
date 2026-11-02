<?php

use App\Host\Data\CertificateRequest;
use App\Host\Data\ContainerSpec;
use App\Host\Data\DatabaseTarget;
use App\Host\Data\ImageBuildSpec;
use App\Host\Data\NginxSite;
use App\Host\Data\Volume;
use App\Host\Drivers\AgentHostDriver;
use App\Host\Exceptions\DockerUnavailable;
use App\Host\Exceptions\HostOperationFailed;
use Illuminate\Support\Facades\Http;

function agentDriver(): AgentHostDriver
{
    return new AgentHostDriver('/nonexistent/agent.sock');
}

it('maps agent errors to HostOperationFailed', function () {
    Http::fake(['agent/*' => Http::response(['error' => 'invalid domain "x"'], 400)]);

    expect(fn () => agentDriver()->applyNginxSite(new NginxSite('demo', ['demo.example.com'], 20001)))
        ->toThrow(HostOperationFailed::class, 'invalid domain');
});

it('maps unexpected status codes to HostOperationFailed', function () {
    Http::fake(['agent/*' => Http::response('boom', 502)]);

    expect(fn () => agentDriver()->systemStatus())->toThrow(HostOperationFailed::class, 'HTTP 502');
});

it('reports an unreachable agent clearly', function () {
    expect(fn () => agentDriver()->systemStatus())
        ->toThrow(HostOperationFailed::class, 'not reachable');
});

it('sends the site as the agent expects it and omits an empty certificate', function () {
    Http::fake(['agent/*' => Http::response(['ok' => true])]);

    agentDriver()->applyNginxSite(new NginxSite('demo', ['demo.example.com'], 20001));

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'http://agent/v1/nginx/sites'
        && $request->data() === ['name' => 'demo', 'domains' => ['demo.example.com'], 'upstream_port' => 20001]);
});

it('sends container specs without empty optional fields', function () {
    Http::fake(['agent/*' => Http::response(['ok' => true])]);

    agentDriver()->runContainer(new ContainerSpec(
        'demo-1', 'commypanel/demo:r1', 20001, 8080,
        env: ['A' => '1'],
        volumes: [new Volume('/var/lib/commypanel/volumes/demo', '/data', true)],
        memoryLimitMb: 256,
    ));
    agentDriver()->runContainer(new ContainerSpec('demo-2', 'commypanel/demo:r1', 20002, 8080));

    $sent = [];
    Http::assertSent(function ($request) use (&$sent) {
        $sent[] = $request->data();

        return true;
    });

    expect($sent[0])->toBe([
        'name' => 'demo-1',
        'image' => 'commypanel/demo:r1',
        'host_port' => 20001,
        'container_port' => 8080,
        'env' => ['A' => '1'],
        'volumes' => [['source' => '/var/lib/commypanel/volumes/demo', 'target' => '/data', 'read_only' => true]],
        'memory_mb' => 256,
    ])->and($sent[1])->not->toHaveKeys(['env', 'volumes', 'memory_mb', 'network']);
});

it('rejects invalid env names and volume paths before calling the agent', function () {
    Http::fake();

    expect(fn () => new ContainerSpec('demo-1', 'img:1', 20001, 8080, env: ['A=B' => 'x']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Volume('relative', '/data'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('maps a 503 to DockerUnavailable', function () {
    Http::fake(['agent/*' => Http::response(['error' => 'docker is not available'], 503)]);

    expect(fn () => agentDriver()->runContainer(new ContainerSpec('demo-1', 'img:1', 20001, 8080)))
        ->toThrow(DockerUnavailable::class);
});

it('streams build output and finishes on ok', function () {
    Http::fake(['agent/*' => Http::response(
        "{\"log\":\"Step 1\"}\n{\"log\":\"Step 2\"}\n{\"ok\":true}\n"
    )]);

    $lines = [];
    agentDriver()->buildImage(new ImageBuildSpec('commypanel/demo:r1', '/var/lib/commypanel/builds/demo'), function (string $line) use (&$lines) {
        $lines[] = $line;
    });

    expect($lines)->toBe(['Step 1', 'Step 2']);
});

it('throws the build error reported by the agent', function () {
    Http::fake(['agent/*' => Http::response("{\"log\":\"Step 1\"}\n{\"error\":\"build failed: boom\"}\n")]);

    expect(fn () => agentDriver()->buildImage(new ImageBuildSpec('commypanel/demo:r1', '/x')))
        ->toThrow(HostOperationFailed::class, 'boom');
});

it('fails when the build stream ends without a result', function () {
    Http::fake(['agent/*' => Http::response("{\"log\":\"Step 1\"}\n")]);

    expect(fn () => agentDriver()->buildImage(new ImageBuildSpec('commypanel/demo:r1', '/x')))
        ->toThrow(HostOperationFailed::class, 'ended unexpectedly');
});

it('maps build validation errors', function () {
    Http::fake(['agent/*' => Http::response(['error' => 'build tags must start with commypanel/'], 400)]);

    expect(fn () => agentDriver()->buildImage(new ImageBuildSpec('library/alpine', '/x')))
        ->toThrow(HostOperationFailed::class, 'commypanel/');
});

it('lists containers, reads logs and prunes images', function () {
    Http::fake([
        'agent/v1/docker/containers/demo-1/logs*' => Http::response(['logs' => "hello\n"]),
        'agent/v1/docker/containers' => Http::response(['containers' => [['name' => 'demo-1', 'image' => 'img:1', 'state' => 'running', 'status' => 'Up']]]),
        'agent/v1/docker/images/prune' => Http::response(['ok' => true, 'space_reclaimed' => 1234]),
    ]);

    $driver = agentDriver();

    expect($driver->listContainers()[0]->name)->toBe('demo-1')
        ->and($driver->containerLogs('demo-1', 50))->toBe("hello\n")
        ->and($driver->pruneDanglingImages())->toBe(1234);
});

it('requests a certificate and maps the answer', function () {
    Http::fake(['agent/v1/certificates/issue' => Http::response([
        'name' => 'shop', 'domains' => ['shop.example.com'], 'issuer' => 'R11',
        'not_before' => '2026-10-30T10:00:00Z', 'not_after' => '2027-01-28T10:00:00Z',
    ])]);

    $info = agentDriver()->issueCertificate(new CertificateRequest('shop', ['shop.example.com'], 'ops@example.com', true));

    expect($info->domains)->toBe(['shop.example.com'])
        ->and($info->notAfter->format('Y-m-d'))->toBe('2027-01-28')
        ->and($info->issuer)->toBe('R11');

    Http::assertSent(fn ($request) => $request->url() === 'http://agent/v1/certificates/issue'
        && $request->data() === ['name' => 'shop', 'domains' => ['shop.example.com'], 'email' => 'ops@example.com', 'staging' => true, 'agree_tos' => true]);
});

it('shows the CA\'s reason when a certificate cannot be issued', function () {
    Http::fake(['agent/*' => Http::response(['error' => 'could not validate shop.example.com: Timeout during connect'], 500)]);

    expect(fn () => agentDriver()->issueCertificate(new CertificateRequest('shop', ['shop.example.com'])))
        ->toThrow(HostOperationFailed::class, 'Timeout during connect');
});

it('treats a missing certificate as null', function () {
    Http::fake(['agent/v1/certificates/shop' => Http::response(['error' => 'no certificate with that name'], 404)]);

    expect(agentDriver()->certificateInfo('shop'))->toBeNull();
});

it('removes a certificate by name', function () {
    Http::fake(['agent/*' => Http::response(['ok' => true])]);

    agentDriver()->removeCertificate('shop');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'http://agent/v1/certificates/shop');
});

describe('server page data', function () {
    it('reads host facts, services and the agent version', function () {
        Http::fake(['agent/*' => Http::response([
            'version' => '0.2.0',
            'info' => [
                'hostname' => 'box', 'os' => 'Debian 13', 'kernel' => '6.8', 'arch' => 'amd64', 'cpus' => 2,
                'uptime_seconds' => 90, 'load' => [0.5, 0.4, 0.3],
                'mem_total_bytes' => 2048, 'mem_available_bytes' => 1024, 'disk_total_bytes' => 100, 'disk_free_bytes' => 40,
                'services' => [['name' => 'nginx', 'state' => 'active'], ['name' => 'docker', 'state' => 'failed']],
            ],
        ])]);

        $info = agentDriver()->systemInfo();

        expect($info->hostname)->toBe('box')
            ->and($info->load)->toBe([0.5, 0.4, 0.3])
            ->and($info->memAvailableBytes)->toBe(1024)
            ->and($info->services[1])->toBe(['name' => 'docker', 'state' => 'failed'])
            ->and($info->agentVersion)->toBe('0.2.0');
    });

    it('tolerates missing fields from an older agent', function () {
        Http::fake(['agent/*' => Http::response(['version' => '0.1.0'])]);

        expect(agentDriver()->systemInfo()->services)->toBe([]);
    });

    it('reads the Docker daemon, including an unreachable one', function () {
        Http::fake(['agent/*' => Http::sequence()
            ->push(['available' => true, 'server_version' => '27.1.0', 'containers' => 5, 'running' => 3, 'images' => 7])
            ->push(['available' => false, 'error' => 'connection refused'])]);

        $up = agentDriver()->dockerInfo();
        $down = agentDriver()->dockerInfo();

        expect($up->available)->toBeTrue()->and($up->serverVersion)->toBe('27.1.0')->and($up->running)->toBe(3)
            ->and($down->available)->toBeFalse()->and($down->error)->toBe('connection refused');
    });

    it('reads images and nginx status', function () {
        Http::fake(['agent/*' => Http::sequence()
            ->push(['images' => [['tags' => ['commypanel/shop:2'], 'size_bytes' => 1000, 'created' => 5]]])
            ->push(['available' => true, 'running' => true, 'version' => '1.26.2', 'config_ok' => false, 'config_output' => 'bad', 'sites' => [['name' => 'shop', 'domains' => ['a.example.com'], 'https' => true, 'modified' => 9]]])]);

        $images = agentDriver()->listImages();
        $nginx = agentDriver()->nginxStatus();

        expect($images[0]->tags)->toBe(['commypanel/shop:2'])->and($images[0]->sizeBytes)->toBe(1000)
            ->and($nginx->version)->toBe('1.26.2')->and($nginx->configOk)->toBeFalse()
            ->and($nginx->sites[0])->toBe(['name' => 'shop', 'domains' => ['a.example.com'], 'https' => true, 'modified' => 9]);
    });

    it('tests and reloads nginx through the agent', function () {
        Http::fake(['agent/*' => Http::sequence()->push(['ok' => false, 'output' => 'emerg'])->push(['ok' => true])]);

        $result = agentDriver()->testNginx();
        agentDriver()->reloadNginx();

        expect($result->ok)->toBeFalse()->and($result->output)->toBe('emerg');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/nginx/reload'));
    });
});

it('lists, browses and previews volumes through the agent', function () {
    Http::fake([
        'agent/v1/volumes/shop/uploads/dir*' => Http::response(['entries' => [['name' => 'photos', 'kind' => 'dir', 'size' => 0, 'modified' => 1700000000]], 'truncated' => true]),
        'agent/v1/volumes/shop/uploads/file*' => Http::response(['size' => 5, 'content' => 'hello', 'binary' => false, 'truncated' => false]),
        'agent/v1/volumes/shop' => Http::response(['volumes' => [['name' => 'uploads', 'bytes' => 99, 'files' => 3, 'partial' => true]]]),
    ]);

    $driver = agentDriver();

    expect($driver->listVolumes('shop')[0])->toMatchObject(['name' => 'uploads', 'bytes' => 99, 'files' => 3, 'partial' => true])
        ->and($driver->browseVolume('shop', 'uploads', 'a b/c')->entries[0])->toMatchObject(['name' => 'photos', 'kind' => 'dir', 'modified' => 1700000000])
        ->and($driver->browseVolume('shop', 'uploads')->truncated)->toBeTrue()
        ->and($driver->previewVolumeFile('shop', 'uploads', 'readme.txt'))->toMatchObject(['size' => 5, 'content' => 'hello']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/volumes/shop/uploads/dir?path=a%20b%2Fc'));
});

it('fetches a volume file as raw bytes and maps its errors', function () {
    Http::fake([
        'agent/v1/volumes/shop/uploads/download*path=ok*' => Http::response("\xff\0raw", 200),
        'agent/v1/volumes/shop/uploads/download*' => Http::response(['error' => 'that file is larger than 25 MB and cannot be fetched here'], 400),
    ]);

    expect(agentDriver()->readVolumeFile('shop', 'uploads', 'ok'))->toBe("\xff\0raw")
        ->and(fn () => agentDriver()->readVolumeFile('shop', 'uploads', 'big'))->toThrow(HostOperationFailed::class, 'larger than 25 MB');
});

it('refuses a volume name that could change the agent request', function () {
    Http::fake();

    foreach (['../x', 'a/b', 'Up', '', 'a b', 'x?y'] as $name) {
        expect(fn () => agentDriver()->browseVolume('shop', $name))->toThrow(InvalidArgumentException::class);
    }

    Http::assertNothingSent();
});

it('maps container networks, published ports and mounts, and lists networks', function () {
    Http::fake([
        'agent/v1/docker/containers' => Http::response(['containers' => [[
            'name' => 'shop-1', 'image' => 'img:1', 'state' => 'running', 'status' => 'Up',
            'networks' => [['name' => 'commypanel', 'ip' => '172.20.0.2']],
            'ports' => [['container' => 8080, 'host' => 20001, 'host_ip' => '127.0.0.1', 'protocol' => 'tcp']],
            'mounts' => [['source' => 'shop/uploads', 'destination' => '/data', 'read_only' => true]],
        ]]]),
        'agent/v1/docker/networks' => Http::response(['networks' => [[
            'name' => 'commypanel', 'driver' => 'bridge', 'subnet' => '172.20.0.0/16', 'gateway' => '172.20.0.1', 'internal' => false,
            'containers' => [['name' => 'shop-1', 'ip' => '172.20.0.2']],
        ]]]),
    ]);

    $container = agentDriver()->listContainers()[0];
    $network = agentDriver()->listNetworks()[0];

    expect($container->networks)->toBe([['name' => 'commypanel', 'ip' => '172.20.0.2']])
        ->and($container->ports)->toBe([['container' => 8080, 'host' => 20001, 'host_ip' => '127.0.0.1', 'protocol' => 'tcp']])
        ->and($container->mounts)->toBe([['source' => 'shop/uploads', 'destination' => '/data', 'read_only' => true]])
        ->and($network)->toMatchObject(['name' => 'commypanel', 'subnet' => '172.20.0.0/16', 'gateway' => '172.20.0.1', 'internal' => false])
        ->and($network->containers)->toBe([['name' => 'shop-1', 'ip' => '172.20.0.2']]);
});

it('copes with an older agent that does not report networks, ports or mounts', function () {
    Http::fake(['agent/v1/docker/containers' => Http::response(['containers' => [['name' => 'shop-1', 'image' => 'i', 'state' => 'running', 'status' => 'Up']]])]);

    expect(agentDriver()->listContainers()[0])->toMatchObject(['networks' => [], 'ports' => [], 'mounts' => []]);
});

it('asks the agent for a terminal ticket and checks the answer', function () {
    Http::fake(['agent/v1/terminal/tickets' => Http::sequence()
        ->push(['ticket' => 'abc', 'expires_in' => 30])
        ->push(['expires_in' => 30])
        ->push(['error' => 'terminals are not enabled on this agent'], 503)]);

    expect(agentDriver()->createTerminalTicket('shop-4', 'https://panel.example.com'))->toBe('abc');

    Http::assertSent(fn ($request) => $request->data() === ['container' => 'shop-4', 'origin' => 'https://panel.example.com']);

    expect(fn () => agentDriver()->createTerminalTicket('shop-4', 'https://panel.example.com'))->toThrow(HostOperationFailed::class, 'ticket')
        ->and(fn () => agentDriver()->createTerminalTicket('shop-4', 'https://panel.example.com'))->toThrow(HostOperationFailed::class, 'not enabled');
});

it('asks the agent to remove an app\'s network', function () {
    Http::fake(['agent/v1/docker/networks/remove' => Http::sequence()
        ->push(['ok' => true])
        ->push(['error' => 'network "commypanel-shop" still has containers attached'], 400)]);

    agentDriver()->removeNetwork('commypanel-shop');

    Http::assertSent(fn ($request) => $request->data() === ['name' => 'commypanel-shop']);

    expect(fn () => agentDriver()->removeNetwork('commypanel-shop'))->toThrow(HostOperationFailed::class, 'still has containers')
        ->and(fn () => agentDriver()->removeNetwork('../x'))->toThrow(InvalidArgumentException::class);
});

it('writes a database dump the agent sends to a file, and only keeps a real one', function () {
    $dir = storage_path('framework/testing/dump-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    $target = new DatabaseTarget('db-main', 'postgres', 'abcdefghijklmnop1234');

    Http::fake(['agent/v1/databases/dump' => Http::sequence()
        ->push(gzencode("-- dump\n"), 200)
        ->push(['error' => 'the dump failed: connection refused'], 400)
        ->push('this is not gzip', 200)]);

    agentDriver()->dumpDatabase($target, 'shop', "{$dir}/ok.sql.gz");

    expect(gzdecode((string) file_get_contents("{$dir}/ok.sql.gz")))->toBe("-- dump\n");

    expect(fn () => agentDriver()->dumpDatabase($target, 'shop', "{$dir}/bad.sql.gz"))->toThrow(HostOperationFailed::class, 'connection refused')
        ->and(fn () => agentDriver()->dumpDatabase($target, 'shop', "{$dir}/odd.sql.gz"))->toThrow(HostOperationFailed::class, 'did not send a dump');

    expect(glob("{$dir}/*"))->toBe(["{$dir}/ok.sql.gz"]);
    File::deleteDirectory($dir);
});

it('asks the agent to load one of its dumps into a database, and passes its refusal on', function () {
    $target = new DatabaseTarget('db-main', 'postgres', 'abcdefghijklmnop1234');

    Http::fake(['agent/v1/databases/restore' => Http::sequence()
        ->push(['ok' => true])
        ->push(['error' => 'this file was not made by CommyPanel, or was changed since, so it is not restored'], 400)]);

    agentDriver()->restoreDatabase($target, 'shop2', 'shop2', 'main', 'shop-20261103-040000.sql.gz');

    Http::assertSent(fn ($request) => $request->data() === [
        'container' => 'db-main', 'engine' => 'postgres', 'root_password' => 'abcdefghijklmnop1234',
        'database' => 'shop2', 'user' => 'shop2', 'server' => 'main', 'file' => 'shop-20261103-040000.sql.gz',
    ]);

    expect(fn () => agentDriver()->restoreDatabase($target, 'shop2', 'shop2', 'main', 'shop-20261103-040000.sql.gz'))
        ->toThrow(HostOperationFailed::class, 'not made by CommyPanel')
        ->and(fn () => agentDriver()->restoreDatabase($target, 'shop2', 'shop2', '../x', 'f'))
        ->toThrow(InvalidArgumentException::class);
});

it('writes a volume archive the agent sends to a file, and only keeps a real one', function () {
    $dir = storage_path('framework/testing/vol-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    Http::fake(['agent/v1/volumes/backup' => Http::sequence()
        ->push(gzencode('tar bytes'), 200)
        ->push(['error' => 'the volume is too big to back up in one piece'], 400)
        ->push('not gzip', 200)]);

    agentDriver()->backupVolume('shop', 'uploads', "{$dir}/ok.tar.gz");

    expect(gzdecode((string) file_get_contents("{$dir}/ok.tar.gz")))->toBe('tar bytes');

    expect(fn () => agentDriver()->backupVolume('shop', 'uploads', "{$dir}/big.tar.gz"))->toThrow(HostOperationFailed::class, 'too big')
        ->and(fn () => agentDriver()->backupVolume('shop', 'uploads', "{$dir}/odd.tar.gz"))->toThrow(HostOperationFailed::class, 'did not send an archive')
        ->and(fn () => agentDriver()->backupVolume('../x', 'uploads', "{$dir}/x.tar.gz"))->toThrow(InvalidArgumentException::class);

    expect(glob("{$dir}/*"))->toBe(["{$dir}/ok.tar.gz"]);
    File::deleteDirectory($dir);
});
