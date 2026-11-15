<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\FastApps\FastApps;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The generated password or key of a quick-install app, read from its current variables. Behind its
 * own permission and a recent password confirmation, and every look is recorded.
 */
class AppCredentialsController extends Controller
{
    public function __invoke(Request $request, App $app, AuditLogger $audit): JsonResponse
    {
        $template = FastApps::find($app->template);
        abort_if($template === null || $app->setup_step !== null, 404);

        $audit->record('app.credentials_viewed', $request->user(), ['app' => $app->slug, 'template' => $template->id()], $app);

        return response()->json(['rows' => $template->credentials($app)])->header('Cache-Control', 'no-store');
    }
}
