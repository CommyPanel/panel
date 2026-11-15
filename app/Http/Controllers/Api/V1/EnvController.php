<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\TokenAccess;
use App\Audit\AuditLogger;
use App\Deploy\EnvLimits;
use App\Models\App;
use App\Models\AppEnvVar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * An app's environment variables, write-only: the API lists the NAMES and sets or removes a
 * variable, but never says what a value is (values often hold secrets). A change takes effect
 * with `POST /apps/{slug}/apply`, as in the panel.
 */
class EnvController extends ApiController
{
    private const KEY_RULES = ['required', 'string', 'max:128', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'not_in:PORT'];

    public function index(Request $request, App $app, TokenAccess $access): JsonResponse
    {
        $this->authorizeApp($request, $app, $access);

        return response()->json(['data' => [
            'keys' => $app->envVars()->orderBy('key')->pluck('key')->values()->all(),
            'pending_changes' => $app->hasPendingChanges(),
        ]]);
    }

    public function update(Request $request, App $app, string $key, TokenAccess $access, AuditLogger $audit): JsonResponse
    {
        $this->authorizeApp($request, $app, $access);

        $data = Validator::make(
            // `value` must be sent (empty is allowed), so it is only passed on when the body has it.
            ['key' => $key, ...($request->exists('value') ? ['value' => $request->input('value')] : [])],
            ['key' => self::KEY_RULES, 'value' => ['present', 'nullable', 'string', 'max:32768']],
            ['key.not_in' => 'PORT is set by CommyPanel.', 'key.regex' => 'Use letters, digits and underscores, not starting with a digit.'],
        )->validate();

        EnvLimits::assertFits($app, [$data['key'] => (string) ($data['value'] ?? '')], 'value');

        $created = ! $app->envVars()->where('key', $data['key'])->exists();
        AppEnvVar::query()->updateOrCreate(['app_id' => $app->id, 'key' => $data['key']], ['value' => (string) ($data['value'] ?? '')]);

        $audit->record('app.env_set', $request->user(), ['app' => $app->slug, 'key' => $data['key']], $app);

        return response()->json(['data' => ['key' => $data['key'], 'pending_changes' => $app->refresh()->hasPendingChanges()]], $created ? 201 : 200);
    }

    public function destroy(Request $request, App $app, string $key, TokenAccess $access, AuditLogger $audit): JsonResponse
    {
        $this->authorizeApp($request, $app, $access);

        $deleted = $app->envVars()->where('key', $key)->delete();
        abort_if($deleted === 0, 404);

        $audit->record('app.env_removed', $request->user(), ['app' => $app->slug, 'key' => $key], $app);

        return response()->json(['data' => ['key' => $key, 'pending_changes' => $app->refresh()->hasPendingChanges()]]);
    }
}
