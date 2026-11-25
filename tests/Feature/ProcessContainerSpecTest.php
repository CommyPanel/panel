<?php

use App\Host\Data\ContainerSpec;
use App\Host\Drivers\AgentHostDriver;
use Illuminate\Support\Facades\Http;

/**
 * What the agent is told about an extra process of an app: its command (an argument list), how long it gets to
 * stop, its restart policy and its name. The web container is sent exactly as before.
 */
it('sends the command, stop timeout, restart policy and process name of a worker, and nothing published', function () {
    Http::fake(['agent/*' => Http::response(['ok' => true])]);

    (new AgentHostDriver('/nonexistent/agent.sock'))->runContainer(new ContainerSpec(
        'shop-1-queue', 'commypanel/shop:1', 0, 0,
        network: 'commypanel-shop',
        command: ['php', 'artisan', 'queue:work', '--tries=3'],
        stopTimeout: 90,
        restart: 'unless-stopped',
        process: 'queue',
    ));

    Http::assertSent(fn ($request) => $request->data() === [
        'name' => 'shop-1-queue',
        'image' => 'commypanel/shop:1',
        'host_port' => 0,
        'container_port' => 0,
        'network' => 'commypanel-shop',
        'command' => ['php', 'artisan', 'queue:work', '--tries=3'],
        'stop_timeout' => 90,
        'restart' => 'unless-stopped',
        'process' => 'queue',
    ]);
});

it('sends the web container without any of the process fields', function () {
    Http::fake(['agent/*' => Http::response(['ok' => true])]);

    (new AgentHostDriver('/nonexistent/agent.sock'))->runContainer(new ContainerSpec('shop-1', 'commypanel/shop:1', 20001, 8080));

    Http::assertSent(fn ($request) => ! array_key_exists('command', $request->data()) && ! array_key_exists('stop_timeout', $request->data()) && ! array_key_exists('restart', $request->data()) && ! array_key_exists('process', $request->data()));
});

it('refuses a command, stop timeout, restart policy or process name that cannot be right', function (callable $make) {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'an empty command' => [fn () => new ContainerSpec('a-1', 'img:1', 0, 0, command: [])],
    'too many arguments' => [fn () => new ContainerSpec('a-1', 'img:1', 0, 0, command: array_fill(0, 65, 'x'))],
    'a negative stop timeout' => [fn () => new ContainerSpec('a-1', 'img:1', 0, 0, stopTimeout: -1)],
    'a stop timeout of ten minutes and a second' => [fn () => new ContainerSpec('a-1', 'img:1', 0, 0, stopTimeout: 601)],
    'an unknown restart policy' => [fn () => new ContainerSpec('a-1', 'img:1', 0, 0, restart: 'always')],
    'a process name with a space' => [fn () => new ContainerSpec('a-1', 'img:1', 0, 0, process: 'two words')],
]);
