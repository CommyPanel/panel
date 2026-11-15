<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\TokenAccess;
use App\Audit\AuditLogger;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Http\Controllers\AppLogController;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The output of an app's running container. Logs often hold secrets, so this has its own ability
 * and permission, and every read is audited.
 */
class LogsController extends ApiController
{
    public function show(Request $request, App $app, TokenAccess $access, HostDriver $host, AuditLogger $audit): JsonResponse
    {
        $this->authorizeApp($request, $app, $access);

        $lines = max(10, min($request->integer('tail', 200), AppLogController::MAX_LINES));
        $name = $app->activeDeployment()->value('container_name');

        $audit->record('api.logs_read', $request->user(), ['app' => $app->slug, 'lines' => $lines], $app);

        if ($name === null) {
            return response()->json(['data' => ['live' => false, 'lines' => $lines, 'logs' => '']]);
        }

        try {
            $logs = $host->containerLogs($name, $lines);
        } catch (HostOperationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        // Programs print bytes that are not text; JSON cannot carry them, so they are replaced.
        return response()->json(['data' => ['live' => true, 'lines' => $lines, 'logs' => mb_scrub($logs, 'UTF-8')]]);
    }
}
