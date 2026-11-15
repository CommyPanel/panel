<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiPresenter;
use App\Api\TokenAccess;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppsController extends ApiController
{
    public function index(Request $request, TokenAccess $access): JsonResponse
    {
        $token = $this->token($request);

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
        $this->authorizeApp($request, $app, $access);

        return response()->json(['data' => ApiPresenter::app($app)]);
    }
}
