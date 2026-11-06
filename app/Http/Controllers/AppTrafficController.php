<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Traffic\TrafficMonitor;
use Illuminate\Http\JsonResponse;

/**
 * How the app's releases have been doing over the last half hour, from the requests nginx
 * proxied: the Traffic card on the Usage tab. Read-only.
 */
class AppTrafficController extends Controller
{
    public const WINDOW_SECONDS = 1800;

    public const STEP_SECONDS = 30;

    public function show(App $app, TrafficMonitor $monitor): JsonResponse
    {
        abort_unless($app->setup_step === null, 404);

        $to = (int) now()->timestamp;
        $from = $to - self::WINDOW_SECONDS;
        $ports = $monitor->ranges($app, $from, $to + 1, self::STEP_SECONDS);

        // A port can have been used by several releases over time: the newest one owns it.
        $byPort = $app->deployments()->whereNotNull('host_port')->latest('number')->limit(12)->get()
            ->sortBy('number')->keyBy('host_port');

        $releases = [];

        foreach ($ports as $port => $stat) {
            $deployment = $byPort->get($port);

            if ($deployment === null) {
                continue;
            }

            $releases[] = [
                'number' => $deployment->number,
                'live' => $app->active_deployment_id === $deployment->id,
                'requests' => $stat->requests,
                'errors' => $stat->errors,
                'error_percent' => round($stat->errorRate() * 100, 1),
                'p95_ms' => $stat->p95,
                'series' => $stat->series,
            ];
        }

        usort($releases, fn (array $a, array $b) => $b['number'] <=> $a['number']);

        return response()->json(['from' => $from, 'to' => $to, 'step' => self::STEP_SECONDS, 'releases' => $releases]);
    }
}
