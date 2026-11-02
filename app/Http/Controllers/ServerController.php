<?php

namespace App\Http\Controllers;

use App\Host\BiggestVolumes;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Server section: what this machine and its Docker and nginx are doing.
 * Every page loads only its own data, and shows a problem instead of failing
 * when the agent or a service cannot be reached.
 */
class ServerController extends Controller
{
    public function index(Request $request, HostDriver $host, BiggestVolumes $volumes): Response
    {
        try {
            $info = $host->systemInfo();
            $error = null;
        } catch (HostOperationFailed $e) {
            $info = null;
            $error = $e->getMessage();
        }

        return Inertia::render('server/overview', [
            'error' => $error,
            // Measuring walks every volume, so it is remembered for a few minutes; ?fresh=1 measures again.
            'volumes' => $info === null ? [] : $volumes->top(5, fresh: $request->boolean('fresh')),
            'host' => $info === null ? null : [
                'hostname' => $info->hostname,
                'os' => $info->os,
                'kernel' => $info->kernel,
                'arch' => $info->arch,
                'cpus' => $info->cpus,
                'uptime_seconds' => $info->uptimeSeconds,
                'load' => $info->load,
                'memory' => ['total' => $info->memTotalBytes, 'available' => $info->memAvailableBytes],
                'disk' => ['total' => $info->diskTotalBytes, 'free' => $info->diskFreeBytes],
                'services' => $info->services,
                'agent_version' => $info->agentVersion,
            ],
        ]);
    }
}
