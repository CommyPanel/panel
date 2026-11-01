<?php

namespace App\Http\Controllers;

use App\Host\Data\ContainerUsage;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\Deployment;
use App\Presenters\UsagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live CPU, memory, disk and network readings for the Server overview. The
 * page polls this; the first call asks for the last few minutes too.
 */
class ServerUsageController extends Controller
{
    public function show(Request $request, HostDriver $host): JsonResponse
    {
        try {
            $usage = $host->resourceUsage($request->boolean('history'));
        } catch (HostOperationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        // Which app each container belongs to, from the deployments that started them.
        $owners = Deployment::query()->with('app:id,slug,name')
            ->whereIn('container_name', array_map(fn (ContainerUsage $c) => $c->name, $usage->containers))
            ->get()->keyBy('container_name');

        return response()->json([
            'interval_ms' => $usage->intervalMs,
            'now' => $usage->now,
            'host' => $usage->host,
            'host_history' => $usage->hostHistory,
            'containers' => array_map(fn (ContainerUsage $c) => [
                ...UsagePresenter::container($c),
                'app' => $owners->get($c->name)?->app?->only(['slug', 'name']),
                'release' => $owners->get($c->name)?->number,
            ], $usage->containers),
        ]);
    }
}
