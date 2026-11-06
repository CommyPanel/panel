<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\EnvParser;
use App\Models\App;
use App\Models\AppEnvVar;
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

        return back()->with('status', "{$data['key']} saved. Apply changes to use it.");
    }

    public function import(Request $request, App $app, EnvParser $parser, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['env' => ['required', 'string', 'max:200000']]);
        $parsed = $parser->parse($data['env']);

        if ($parsed['vars'] === []) {
            return back()->withErrors(['env' => 'No variables found. Use one KEY=value per line.']);
        }

        foreach ($parsed['vars'] as $key => $value) {
            AppEnvVar::query()->updateOrCreate(['app_id' => $app->id, 'key' => $key], ['value' => $value]);
        }

        $audit->record('app.env_imported', null, ['app' => $app->slug, 'keys' => array_keys($parsed['vars'])], $app);

        $skipped = count($parsed['skipped']);
        $message = count($parsed['vars']).' variable(s) saved. Apply changes to use them.'
            .($skipped > 0 ? " {$skipped} line(s) were skipped." : '');

        return back()->with('status', $message);
    }

    public function destroy(App $app, string $key, AuditLogger $audit): RedirectResponse
    {
        $app->envVars()->where('key', $key)->delete();
        $audit->record('app.env_removed', null, ['app' => $app->slug, 'key' => $key], $app);

        return back()->with('status', "{$key} removed. Apply the changes to stop using it.");
    }
}
