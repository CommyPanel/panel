<?php

namespace App\Http\Controllers;

use App\Deploy\ContainerOwners;
use App\Deploy\ReleaseProcesses;
use App\Host\Data\ContainerInfo;
use App\Host\Data\NetworkInfo;
use App\Host\Exceptions\DockerUnavailable;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Deployment;
use App\Presenters\ContainerPresenter;
use Illuminate\Http\JsonResponse;

/**
 * The containers of one app (the live release, and an older one while it
 * drains), the networks they are on, and what else is on those networks.
 */
class AppContainerController extends Controller
{
    public function show(App $app, HostDriver $host, ReleaseProcesses $processes): JsonResponse
    {
        try {
            $containers = $host->listContainers();
            $networks = $host->listNetworks();
        } catch (DockerUnavailable $e) {
            return response()->json(['error' => $e->getMessage(), 'docker_down' => true], 503);
        } catch (HostOperationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        // The web container of each release, and its extra processes (workers, scheduler): container => [release, process].
        $known = [];

        foreach ($app->deployments()->whereNotNull('container_name')->get() as $d) {
            $known[$d->container_name] = ['release' => $d->number, 'process' => 'web'];

            foreach ($processes->entries($d) as $entry) {
                foreach ($entry['containers'] as $container) {
                    $known[$container] = ['release' => $d->number, 'process' => $entry['name']];
                }
            }
        }

        $releases = collect($known)->map(fn (array $k) => $k['release']);
        $own = collect($containers)->filter(fn (ContainerInfo $c) => $releases->has($c->name))->values();
        $live = array_column($processes->lookable($app->activeDeployment()->first()), 'container');

        // Which app every container on those networks belongs to.
        $owners = ContainerOwners::for(array_map(fn (ContainerInfo $c) => $c->name, $containers))
            ->map(fn (Deployment $d) => $d->app?->only(['slug', 'name']));

        $onNetworks = $own->flatMap(fn (ContainerInfo $c) => array_column($c->networks, 'name'))->unique();

        return response()->json([
            'containers' => $own->map(fn (ContainerInfo $c) => [
                ...ContainerPresenter::container($c),
                'release' => $releases->get($c->name),
                'process' => $known[$c->name]['process'] ?? 'web',
                'live' => in_array($c->name, $live, true),
            ])->all(),
            'networks' => collect($networks)
                ->filter(fn (NetworkInfo $n) => $onNetworks->contains($n->name))
                ->map(fn (NetworkInfo $n) => [
                    ...ContainerPresenter::network($n),
                    'containers' => array_map(fn (array $m) => [
                        ...$m,
                        'app' => $owners->get($m['name']),
                        'this_app' => $releases->has($m['name']),
                    ], $n->containers),
                ])->values()->all(),
        ]);
    }
}
