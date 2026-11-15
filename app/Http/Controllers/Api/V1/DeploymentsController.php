<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiPresenter;
use App\Api\TokenAccess;
use App\Audit\AuditLogger;
use App\Deploy\Deployer;
use App\Deploy\DeploymentFailed;
use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeploymentsController extends ApiController
{
    /** The newest deployments of an app, newest first. */
    public function index(Request $request, App $app, TokenAccess $access): JsonResponse
    {
        $this->authorizeApp($request, $app, $access);

        $limit = max(1, min($request->integer('limit', 20), 50));

        return response()->json(['data' => $app->deployments()->orderByDesc('number')->limit($limit)->get()
            ->map(fn ($deployment) => ApiPresenter::deployment($app, $deployment))->values()]);
    }

    /**
     * Start a deployment, as the Deploy button does. It answers at once with 202 and the
     * deployment's number; follow it with GET .../deployments/{number}.
     */
    public function store(Request $request, App $app, TokenAccess $access, Deployer $deployer, AuditLogger $audit): JsonResponse
    {
        $this->authorizeApp($request, $app, $access);

        $status = $app->load('latestDeployment')->status();

        if (in_array($status, [AppStatus::Stopped, AppStatus::Deactivated], true)) {
            return response()->json(['error' => 'The app is stopped. Start it in CommyPanel first.'], 409);
        }

        if (($running = $this->running($app)) !== null) {
            return $this->conflict($app, $running);
        }

        $deployment = $deployer->deploy($app, $request->user());
        $audit->record('api.deploy_triggered', $request->user(), ['app' => $app->slug, 'deployment' => $deployment->number], $app);

        return response()->json(['data' => ApiPresenter::deployment($app, $deployment)], 202);
    }

    /**
     * Make changed variables, volumes and links take effect without a build, from the version that
     * is already running (the "Apply changes" button).
     */
    public function apply(Request $request, App $app, TokenAccess $access, Deployer $deployer): JsonResponse
    {
        $this->authorizeApp($request, $app, $access);

        if (($running = $this->running($app)) !== null) {
            return $this->conflict($app, $running);
        }

        try {
            $deployment = $deployer->apply($app->load('activeDeployment'), $request->user());
        } catch (DeploymentFailed $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => ApiPresenter::deployment($app, $deployment)], 202);
    }

    public function show(Request $request, App $app, int $number, TokenAccess $access): JsonResponse
    {
        $this->authorizeApp($request, $app, $access);

        $deployment = $app->deployments()->where('number', $number)->firstOrFail();

        return response()->json(['data' => ApiPresenter::deployment($app, $deployment, withLog: $request->boolean('log'))]);
    }

    private function running(App $app): ?Deployment
    {
        return $app->deployments()->whereIn('status', DeploymentStatus::working())->orderByDesc('number')->first();
    }

    private function conflict(App $app, Deployment $running): JsonResponse
    {
        return response()->json([
            'error' => 'A deployment is already in progress.',
            'deployment' => ApiPresenter::deployment($app, $running),
        ], 409);
    }
}
