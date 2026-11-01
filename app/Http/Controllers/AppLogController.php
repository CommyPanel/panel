<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The output of an app's running container. Logs often hold secrets, so reading
 * them is its own permission and a download is audited.
 */
class AppLogController extends Controller
{
    public const MAX_LINES = 5000;

    public function show(Request $request, App $app, HostDriver $host): JsonResponse
    {
        $lines = $this->lines($request->integer('tail', 500));
        $name = $app->activeDeployment()->value('container_name');

        if ($name === null) {
            return response()->json(['live' => false, 'logs' => '', 'lines' => $lines]);
        }

        try {
            $logs = $host->containerLogs($name, $lines);
        } catch (HostOperationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json(['live' => true, 'logs' => $logs, 'lines' => $lines]);
    }

    public function download(Request $request, App $app, HostDriver $host, AuditLogger $audit): Response
    {
        $name = $app->activeDeployment()->value('container_name');

        if ($name === null) {
            return response('This app has no running container, so there are no logs to download.', 404);
        }

        try {
            $logs = $host->containerLogs($name, self::MAX_LINES);
        } catch (HostOperationFailed $e) {
            return response($e->getMessage(), 502);
        }

        $audit->record('app.logs_downloaded', $request->user(), ['app' => $app->slug], $app);

        return response($logs, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$app->slug.'-'.now()->format('Y-m-d-His').'.log"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function lines(int $requested): int
    {
        return max(10, min($requested, self::MAX_LINES));
    }
}
