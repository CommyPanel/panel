<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\ReleaseProcesses;
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

    public function show(Request $request, App $app, HostDriver $host, ReleaseProcesses $processes): JsonResponse
    {
        $lines = $this->lines($request->integer('tail', 500));
        $containers = $processes->lookable($app->activeDeployment()->first());
        $name = $this->pick($containers, $request->query('container'));

        if ($name === null) {
            return response()->json(['live' => false, 'logs' => '', 'lines' => $lines, 'containers' => []]);
        }

        try {
            $logs = $host->containerLogs($name, $lines);
        } catch (HostOperationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        // Programs print bytes that are not text; JSON cannot carry them, so they are replaced.
        return response()->json(['live' => true, 'logs' => mb_scrub($logs, 'UTF-8'), 'lines' => $lines, 'container' => $name, 'containers' => $containers]);
    }

    /**
     * The container asked for, if it is one of the live release's; else the web one.
     *
     * @param  list<array{container: string, label: string, process: string}>  $containers
     */
    private function pick(array $containers, mixed $requested): ?string
    {
        foreach ($containers as $c) {
            if ($c['container'] === $requested) {
                return $c['container'];
            }
        }

        return $containers[0]['container'] ?? null;
    }

    public function download(Request $request, App $app, HostDriver $host, AuditLogger $audit, ReleaseProcesses $processes): Response
    {
        $containers = $processes->lookable($app->activeDeployment()->first());
        $name = $this->pick($containers, $request->query('container'));
        $process = collect($containers)->firstWhere('container', $name)['process'] ?? 'web';

        if ($name === null) {
            return response('This app has no running container, so there are no logs to download.', 404);
        }

        try {
            $logs = $host->containerLogs($name, self::MAX_LINES);
        } catch (HostOperationFailed $e) {
            return response($e->getMessage(), 502);
        }

        $audit->record('app.logs_downloaded', $request->user(), ['app' => $app->slug, 'container' => $name], $app);

        return response($logs, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$app->slug.($process === 'web' ? '' : '-'.$process).'-'.now()->format('Y-m-d-His').'.log"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function lines(int $requested): int
    {
        return max(10, min($requested, self::MAX_LINES));
    }
}
