<?php

namespace App\Http\Controllers;

use App\Deploy\ReleaseProcesses;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Presenters\UsagePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live CPU, memory and network readings for an app's running container.
 */
class AppUsageController extends Controller
{
    public function show(Request $request, App $app, HostDriver $host, ReleaseProcesses $processes): JsonResponse
    {
        $containers = $processes->lookable($app->activeDeployment()->first());
        $name = $containers[0]['container'] ?? null;

        foreach ($containers as $candidate) {
            if ($candidate['container'] === $request->query('container')) {
                $name = $candidate['container'];
            }
        }

        if ($name === null) {
            return response()->json(['live' => false, 'container' => null, 'interval_ms' => 2000]);
        }

        try {
            $usage = $host->resourceUsage($request->boolean('history'));
        } catch (HostOperationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $container = collect($usage->containers)->first(fn ($c) => $c->name === $name);

        return response()->json([
            'live' => true,
            'containers' => $containers,
            'interval_ms' => $usage->intervalMs,
            // Null while the container is stopped or has not been sampled twice yet.
            'container' => $container === null ? null : UsagePresenter::container($container),
            'memory_limit_mb' => $app->memory_limit_mb,
        ]);
    }
}
