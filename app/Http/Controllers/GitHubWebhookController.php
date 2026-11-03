<?php

namespace App\Http\Controllers;

use App\Deploy\Webhooks\PushDeploy;
use App\Deploy\Webhooks\PushWebhook;
use App\Enums\SourceType;
use App\GitHub\GitHubApp;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives the GitHub App's webhook: one address for every repository it may read. No session
 * or CSRF; the signature, made with the secret GitHub generated for this app, is the only
 * proof, so nothing in the request is used before it has been checked.
 */
class GitHubWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 5 * 1024 * 1024;

    public function __invoke(Request $request, GitHubApp $github, PushDeploy $push): JsonResponse
    {
        $secret = $github->webhookSecret();

        if ($secret === null) {
            return response()->json(['error' => 'Unknown webhook.'], 404);
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'Payload too large.'], 413);
        }

        $signature = strtolower((string) $request->header('X-Hub-Signature-256'));

        if (! str_starts_with($signature, 'sha256=') || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), substr($signature, 7))) {
            Log::warning('GitHub webhook with a wrong or missing signature.', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid signature.'], 403);
        }

        $event = strtolower((string) $request->header('X-GitHub-Event'));

        if ($event === 'ping') {
            return response()->json(['message' => 'CommyPanel received the test event.']);
        }

        $payload = $request->json()->all();
        $repo = $payload['repository']['full_name'] ?? null;

        // Only this app's own installation counts.
        if ($event !== 'push' || ! is_string($repo) || preg_match(GitHubApp::REPO, $repo) !== 1 || ($payload['installation']['id'] ?? null) !== $github->installationId()) {
            return response()->json(['message' => 'Ignored: not a push to a repository of this installation.'], 202);
        }

        $webhook = new PushWebhook($request);
        $branch = $webhook->branch();
        $queued = 0;

        $apps = App::query()
            ->whereRaw('lower(github_repo) = ?', [strtolower($repo)])
            ->whereNull('setup_step')
            ->where('github_auto_deploy', true)
            ->where('source_type', SourceType::Git->value)
            ->get();

        foreach ($apps as $app) {
            if ($push->handle($app, $branch, true, $webhook->commit(), $request->ip())['queued']) {
                $queued++;
            }
        }

        return response()->json(['message' => "{$queued} deployment(s) queued."], 202);
    }
}
