<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\ReleaseProcesses;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Opening a terminal in an app's container. The terminal itself is a WebSocket
 * the browser opens against the agent through nginx; this only hands out the
 * single-use ticket for it, after the permission, a recent password
 * confirmation and a rate limit have been checked, and after the audit entry
 * is written. The ticket names one container and the origin the page is on.
 */
class AppTerminalController extends Controller
{
    public function ticket(Request $request, App $app, HostDriver $host, AuditLogger $audit, ReleaseProcesses $processes): JsonResponse
    {
        // The web container, or another container of the live release when one is asked for.
        $containers = $processes->lookable($app->activeDeployment()->first());
        $container = $containers[0]['container'] ?? null;

        foreach ($containers as $candidate) {
            if ($candidate['container'] === $request->input('container')) {
                $container = $candidate['container'];
            }
        }

        if ($container === null || $app->stopped_at !== null) {
            return response()->json(['error' => 'The app has no running container to open a terminal in.'], 422);
        }

        $origin = $request->getSchemeAndHttpHost();

        $audit->record('app.terminal_opened', $request->user(), ['app' => $app->slug, 'container' => $container], $app);

        try {
            $ticket = $host->createTerminalTicket($container, $origin);
        } catch (HostOperationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json([
            'ticket' => $ticket,
            'path' => '/terminal',
            'container' => $container,
            'containers' => $containers,
            'expires_in' => 30,
        ]);
    }
}
