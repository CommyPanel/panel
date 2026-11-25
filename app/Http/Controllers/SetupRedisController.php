<?php

namespace App\Http\Controllers;

use App\Auth\Permissions;
use App\Databases\DatabaseException;
use App\Deploy\SetupAppRedis;
use App\Enums\SetupStep;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Redis choice of the Laravel wizard (JSON; the step component calls it). Draft apps past the source
 * step only.
 */
class SetupRedisController extends Controller
{
    public function update(Request $request, App $app, SetupAppRedis $setup): JsonResponse
    {
        abort_unless($app->setup_step !== null && $app->setup_step !== SetupStep::Source, 404);

        $data = $request->validate([
            'mode' => ['required', Rule::in(SetupAppRedis::MODES)],
            'app' => ['required_if:mode,'.SetupAppRedis::EXISTING, 'nullable', 'string', 'max:60'],
        ]);

        $user = $request->user();
        $mode = $data['mode'];

        // Linking hands the app the Redis password; a new Redis is a new app.
        abort_if($mode !== SetupAppRedis::NONE && ! $user->can(Permissions::APPS_LINKS), 403);
        abort_if($mode === SetupAppRedis::NEW && ! $user->can(Permissions::APPS_CREATE), 403);

        try {
            $setup->apply($app, $data, $user);
        } catch (DatabaseException $e) {
            throw ValidationException::withMessages([$mode === SetupAppRedis::EXISTING ? 'app' : 'mode' => $e->getMessage()]);
        }

        return response()->json(['choice' => $setup->choice($app->fresh())]);
    }
}
