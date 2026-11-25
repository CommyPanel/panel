<?php

use App\Host\Data\ContainerSpec;
use App\Host\Data\ImageBuildSpec;
use App\Host\Data\NginxSite;
use App\Host\Drivers\AgentHostDriver;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;

it('resolves the fake driver by default in tests', function () {
    expect(app(HostDriver::class))->toBeInstanceOf(FakeHostDriver::class)
        ->and(app(HostDriver::class))->toBe(app(HostDriver::class));
});

it('records host actions in order', function () {
    $driver = app(HostDriver::class);

    $driver->buildImage(new ImageBuildSpec('demo:1', '/tmp/src'), fn (string $line) => $lines[] = $line);
    $driver->runContainer(new ContainerSpec('demo-1', 'demo:1', 20001, 8080));
    $driver->applyNginxSite(new NginxSite('demo', ['demo.example.com'], 20001));

    expect($driver->actionNames())->toBe(['buildImage', 'runContainer', 'applyNginxSite'])
        ->and($driver->sites())->toHaveKey('demo')
        ->and($driver->containers())->toHaveKey('demo-1');
});

it('can simulate a failure once', function () {
    $driver = app(HostDriver::class)->failOn('runContainer');
    $spec = new ContainerSpec('demo-1', 'demo:1', 20001, 8080);

    expect(fn () => $driver->runContainer($spec))->toThrow(HostOperationFailed::class);

    $driver->runContainer($spec);
    expect($driver->containers())->toHaveKey('demo-1');
});

it('accepts a wildcard name in a site', function () {
    expect((new NginxSite('demo', ['*.example.com', 'example.com'], 20001))->domains)->toBe(['*.example.com', 'example.com']);
});

it('rejects invalid names, domains and ports', function (callable $make) {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'uppercase name' => [fn () => new ContainerSpec('Demo', 'demo:1', 20001, 8080)],
    'shell injection in name' => [fn () => new ContainerSpec('a;rm -rf', 'demo:1', 20001, 8080)],
    'privileged port' => [fn () => new ContainerSpec('demo', 'demo:1', 80, 8080)],
    'no domains' => [fn () => new NginxSite('demo', [], 20001)],
    'bad domain' => [fn () => new NginxSite('demo', ['bad domain;'], 20001)],
    'wildcard of a single label' => [fn () => new NginxSite('demo', ['*.com'], 20001)],
    'wildcard in the middle' => [fn () => new NginxSite('demo', ['a.*.example.com'], 20001)],
    'bare star' => [fn () => new NginxSite('demo', ['*'], 20001)],
    'bad upstream port' => [fn () => new NginxSite('demo', ['a.example.com'], 99999)],
    'redirect to an unknown domain' => [fn () => new NginxSite('demo', ['a.example.com', 'b.example.com'], 20001, null, ['a.example.com' => 'c.example.com'])],
    'redirect to itself' => [fn () => new NginxSite('demo', ['a.example.com', 'b.example.com'], 20001, null, ['a.example.com' => 'a.example.com'])],
    'redirect chain' => [fn () => new NginxSite('demo', ['a.example.com', 'b.example.com', 'c.example.com'], 20001, null, ['a.example.com' => 'b.example.com', 'b.example.com' => 'c.example.com'])],
    'everything redirected' => [fn () => new NginxSite('demo', ['a.example.com', 'b.example.com'], 20001, null, ['a.example.com' => 'b.example.com', 'b.example.com' => 'a.example.com'])],
]);

it('binds the agent driver only on Linux unless explicitly allowed', function () {
    config(['commypanel.host_driver' => 'agent', 'commypanel.agent_allow_non_linux' => false]);
    app()->forgetInstance(HostDriver::class);

    if (PHP_OS_FAMILY === 'Linux') {
        expect(app(HostDriver::class))->toBeInstanceOf(AgentHostDriver::class);

        return;
    }

    expect(fn () => app(HostDriver::class))->toThrow(RuntimeException::class, 'only runs on Linux');

    config(['commypanel.agent_allow_non_linux' => true]);
    app()->forgetInstance(HostDriver::class);
    expect(app(HostDriver::class))->toBeInstanceOf(AgentHostDriver::class);
});
