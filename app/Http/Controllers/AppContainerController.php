<?php

namespace App\Http\Controllers;

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
    public function show(App $app, HostDriver $host): JsonResponse
    {
        try {
            $containers = $host->listContainers();
            $networks = $host->listNetworks();
        } catch (DockerUnavailable $e) {
            return response()->json(['error' => $e->getMessage(), 'docker_down' => true], 503);
        } catch (HostOperationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $releases = $app->deployments()->whereNotNull('container_name')->pluck('number', 'container_name');
        $own = collect($containers)->filter(fn (ContainerInfo $c) => $releases->has($c->name))->values();

        // Which app every container on those networks belongs to.
        $owners = Deployment::query()->with('app:id,slug,name')->get(['container_name', 'app_id'])
            ->filter(fn (Deployment $d) => $d->container_name !== null)
            ->mapWithKeys(fn (Deployment $d) => [$d->container_name => $d->app?->only(['slug', 'name'])]);

        $onNetworks = $own->flatMap(fn (ContainerInfo $c) => array_column($c->networks, 'name'))->unique();

        return response()->json([
            'containers' => $own->map(fn (ContainerInfo $c) => [
                ...ContainerPresenter::container($c),
                'release' => $releases->get($c->name),
                'live' => $c->name === $app->activeDeployment()->value('container_name'),
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
