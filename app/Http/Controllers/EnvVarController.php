<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\EnvParser;
use App\Models\App;
use App\Models\AppEnvVar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnvVarController extends Controller
{
    public function store(Request $request, App $app, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'not_in:PORT'],
            'value' => ['present', 'nullable', 'string', 'max:32768'],
        ], ['key.not_in' => 'PORT is set by CommyPanel.', 'key.regex' => 'Use letters, digits and underscores, not starting with a digit.']);

        AppEnvVar::query()->updateOrCreate(
            ['app_id' => $app->id, 'key' => $data['key']],
            ['value' => (string) ($data['value'] ?? '')],
        );

        $audit->record('app.env_set', null, ['app' => $app->slug, 'key' => $data['key']], $app);

        return back()->with('status', __(':key saved. Apply changes to use it.', ['key' => $data['key']]));
    }

    public function import(Request $request, App $app, EnvParser $parser, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['env' => ['required', 'string', 'max:200000']]);
        $parsed = $parser->parse($data['env']);

        if ($parsed['vars'] === []) {
            return back()->withErrors(['env' => __('No variables found. Use one KEY=value per line.')]);
        }

        foreach ($parsed['vars'] as $key => $value) {
            AppEnvVar::query()->updateOrCreate(['app_id' => $app->id, 'key' => $key], ['value' => $value]);
        }

        $audit->record('app.env_imported', null, ['app' => $app->slug, 'keys' => array_keys($parsed['vars'])], $app);

        $skipped = count($parsed['skipped']);
        $saved = count($parsed['vars']);
        $message = $skipped > 0
            ? __(':count variable(s) saved. Apply changes to use them. :skipped line(s) were skipped.', ['count' => $saved, 'skipped' => $skipped])
            : __(':count variable(s) saved. Apply changes to use them.', ['count' => $saved]);

        return back()->with('status', $message);
    }

    public function destroy(App $app, string $key, AuditLogger $audit): RedirectResponse
    {
        $app->envVars()->where('key', $key)->delete();
        $audit->record('app.env_removed', null, ['app' => $app->slug, 'key' => $key], $app);

        return back()->with('status', __(':key removed. Apply the changes to stop using it.', ['key' => $key]));
    }

    /**
     * The saved values, for the app's owner to read and edit. Behind a recent
     * password; the values are never part of a page's props.
     */
    public function reveal(App $app, AuditLogger $audit): JsonResponse
    {
        $audit->record('app.env_revealed', null, ['app' => $app->slug], $app);

        $vars = $app->envVars()->orderBy('key')->get()
            ->map(fn (AppEnvVar $var) => ['key' => $var->key, 'value' => (string) $var->value])
            ->values()->all();

        return response()->json(['vars' => $vars], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * Change the value of variables that already exist (a name that does not
     * exist is ignored; adding goes through `store`).
     */
    public function update(Request $request, App $app, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'vars' => ['required', 'array', 'min:1', 'max:200'],
            'vars.*' => ['present', 'nullable', 'string', 'max:32768'],
        ]);

        $existing = $app->envVars()->get()->keyBy('key');
        $changed = [];

        foreach ($data['vars'] as $key => $value) {
            $var = $existing->get((string) $key);

            if ($var !== null && (string) $var->value !== (string) ($value ?? '')) {
                $var->update(['value' => (string) ($value ?? '')]);
                $changed[] = (string) $key;
            }
        }

        if ($changed === []) {
            return back()->with('status', __('Nothing changed.'));
        }

        $audit->record('app.env_changed', null, ['app' => $app->slug, 'keys' => $changed], $app);

        return back()->with('status', __(':count variable(s) saved. Apply changes to use them.', ['count' => count($changed)]));
    }

    public function clear(App $app, AuditLogger $audit): RedirectResponse
    {
        $keys = $app->envVars()->pluck('key')->all();
        $app->envVars()->delete();
        $audit->record('app.env_cleared', null, ['app' => $app->slug, 'keys' => $keys], $app);

        return back()->with('status', __('All :count variable(s) removed. Apply the changes to stop using them.', ['count' => count($keys)]));
    }
}
