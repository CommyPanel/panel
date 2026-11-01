<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\Deployer;
use App\Deploy\Webhooks\PushWebhook;
use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Enums\SourceType;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives push webhooks and deploys the app when its branch was pushed.
 * The route has no session or CSRF: the signature is the only proof, so
 * nothing in the request is used before it has been verified.
 */
class PushWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 5 * 1024 * 1024;

    public function __invoke(Request $request, string $hook, Deployer $deployer, AuditLogger $audit): JsonResponse
    {
        $app = preg_match('/^[0-9a-f]{32}$/', $hook) === 1
            ? App::query()->where('webhook_id', $hook)->first()
            : null;

        if ($app === null || $app->webhook_secret === null || $app->setup_step !== null || $app->source_type !== SourceType::Git) {
            return response()->json(['error' => 'Unknown webhook.'], 404);
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'Payload too large.'], 413);
        }

        $webhook = new PushWebhook($request);

        if (! $webhook->verify($app->webhook_secret)) {
            Log::warning('Push webhook with a wrong or missing signature.', ['app' => $app->slug, 'ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid signature.'], 403);
        }

        if ($webhook->isPing()) {
            return response()->json(['message' => 'CommyPanel received the test event.']);
        }

        $branch = $webhook->branch();

        $skip = match (true) {
            ! $webhook->isPush() => 'Not a push event.',
            $branch !== $app->git_branch => "Not a push to {$app->git_branch}.",
            in_array($app->load('latestDeployment')->status(), [AppStatus::Stopped, AppStatus::Deactivated], true) => 'The app is stopped; start it in CommyPanel first.',
            $app->deployments()->where('status', DeploymentStatus::Queued)->exists() => 'A deployment is already waiting to run.',
            default => null,
        };

        if ($skip !== null) {
            return response()->json(['message' => "Ignored: {$skip}"], 202);
        }

        $deployment = $deployer->deploy($app);
        $audit->record('app.webhook_deploy', null, [
            'app' => $app->slug,
            'deployment' => $deployment->number,
            'commit' => $webhook->commit(),
            'ip' => $request->ip(),
        ], $app);

        return response()->json(['message' => "Deployment #{$deployment->number} queued."], 202);
    }
}
