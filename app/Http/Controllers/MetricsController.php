<?php

namespace App\Http\Controllers;

use App\Api\TokenAccess;
use App\Metrics\ServerMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The numbers a Prometheus collects (see docs/metrics.md). A bearer token with the metrics:read
 * ability, like the API; a token limited to some apps only sees those apps.
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request, ServerMetrics $metrics, TokenAccess $access): Response
    {
        $token = $request->user()->currentAccessToken();

        return response($metrics->render($access->appsOf($token)), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
