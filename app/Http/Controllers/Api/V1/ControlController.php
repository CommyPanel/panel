<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiPresenter;
use App\Api\TokenAccess;
use App\Deploy\ControlApp;
use App\Deploy\DeploymentFailed;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Start, stop and restart an app's live container, as the buttons on the app page do (the audit
 * entry is the same and names the token).
 */
class ControlController extends ApiController
{
    public function stop(Request $request, App $app, TokenAccess $access, ControlApp $control): JsonResponse
    {
        return $this->run($request, $app, $access, fn () => $control->stop($app, $request->user()));
    }

    public function start(Request $request, App $app, TokenAccess $access, ControlApp $control): JsonResponse
    {
        return $this->run($request, $app, $access, fn () => $control->start($app, $request->user()));
    }

    public function restart(Request $request, App $app, TokenAccess $access, ControlApp $control): JsonResponse
    {
        return $this->run($request, $app, $access, fn () => $control->restart($app, $request->user()));
    }

    /**
     * @param  callable(): void  $action
     */
    private function run(Request $request, App $app, TokenAccess $access, callable $action): JsonResponse
    {
        $this->authorizeApp($request, $app, $access);

        if ($app->isDeploying()) {
            return response()->json(['error' => 'A deployment is in progress. Wait for it to finish.'], 409);
        }

        try {
            $action();
        } catch (DeploymentFailed $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => ApiPresenter::app($app->refresh())]);
    }
}
