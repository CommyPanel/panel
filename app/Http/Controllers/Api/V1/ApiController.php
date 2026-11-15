<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\TokenAccess;
use App\Http\Controllers\Controller;
use App\Models\App;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared by the API controllers: which apps the token may reach.
 */
abstract class ApiController extends Controller
{
    protected function token(Request $request): PersonalAccessToken
    {
        // Only a bearer token gets this far (the route group is auth:sanctum with no session).
        return $request->user()->currentAccessToken();
    }

    /**
     * An app the token may not use (or that is still being set up) looks the same as one that
     * does not exist.
     */
    protected function authorizeApp(Request $request, App $app, TokenAccess $access): void
    {
        abort_unless($app->setup_step === null && $access->allowsApp($this->token($request), $app), 404);
    }
}
