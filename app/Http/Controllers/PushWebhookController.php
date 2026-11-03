<?php

namespace App\Http\Controllers;

use App\Deploy\Webhooks\PushDeploy;
use App\Deploy\Webhooks\PushWebhook;
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

    public function __invoke(Request $request, string $hook, PushDeploy $push): JsonResponse
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

        $result = $push->handle($app, $webhook->branch(), $webhook->isPush(), $webhook->commit(), $request->ip());

        return response()->json(['message' => $result['message']], 202);
    }
}
