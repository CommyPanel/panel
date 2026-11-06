<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiPresenter;
use App\Api\TokenAccess;
use App\Audit\AuditLogger;
use App\Deploy\Deployer;
use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeploymentsController extends Controller
{
    /**
     * Start a deployment, as the Deploy button does. It answers at once with 202 and the
     * deployment's number; follow it with GET .../deployments/{number}.
     */
    public function store(Request $request, App $app, TokenAccess $access, Deployer $deployer, AuditLogger $audit): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        abort_unless($app->setup_step === null && $access->allowsApp($token, $app), 404);

        $status = $app->load('latestDeployment')->status();

        if (in_array($status, [AppStatus::Stopped, AppStatus::Deactivated], true)) {
            return response()->json(['error' => 'The app is stopped. Start it in CommyPanel first.'], 409);
        }

        $running = $app->deployments()
            ->whereIn('status', DeploymentStatus::working())
            ->orderByDesc('number')
            ->first();

        if ($running !== null) {
            return response()->json([
                'error' => 'A deployment is already in progress.',
                'deployment' => ApiPresenter::deployment($app, $running),
            ], 409);
        }

        $deployment = $deployer->deploy($app, $request->user());
        $audit->record('api.deploy_triggered', $request->user(), ['app' => $app->slug, 'deployment' => $deployment->number, 'token_name' => $token->name], $app);

        return response()->json(['data' => ApiPresenter::deployment($app, $deployment)], 202);
    }

    public function show(Request $request, App $app, int $number, TokenAccess $access): JsonResponse
    {
        abort_unless($app->setup_step === null && $access->allowsApp($request->user()->currentAccessToken(), $app), 404);

        $deployment = $app->deployments()->where('number', $number)->firstOrFail();

        return response()->json(['data' => ApiPresenter::deployment($app, $deployment, withLog: $request->boolean('log'))]);
    }
}
