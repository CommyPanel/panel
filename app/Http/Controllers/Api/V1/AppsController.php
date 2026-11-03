<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiPresenter;
use App\Api\TokenAccess;
use App\Http\Controllers\Controller;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppsController extends Controller
{
    public function index(Request $request, TokenAccess $access): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        $apps = App::query()
            ->whereNull('setup_step')
            ->with(['latestDeployment', 'activeDeployment', 'domains'])
            ->orderBy('name')
            ->get()
            ->filter(fn (App $app) => $access->allowsApp($token, $app))
            ->map(fn (App $app) => ApiPresenter::app($app))
            ->values();

        return response()->json(['data' => $apps]);
    }

    public function show(Request $request, App $app, TokenAccess $access): JsonResponse
    {
        // An app the token may not use looks the same as one that does not exist.
        abort_unless($app->setup_step === null && $access->allowsApp($request->user()->currentAccessToken(), $app), 404);

        return response()->json(['data' => ApiPresenter::app($app)]);
    }
}
