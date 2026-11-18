<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\EnvLimits;
use App\Deploy\EnvParser;
use App\Models\App;
use App\Models\AppEnvVar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EnvVarController extends Controller
{
    public function store(Request $request, App $app, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'not_in:PORT'],
            'value' => ['present', 'nullable', 'string', 'max:32768'],
        ], ['key.not_in' => 'PORT is set by CommyPanel.', 'key.regex' => 'Use letters, digits and underscores, not starting with a digit.']);

        EnvLimits::assertFits($app, [$data['key'] => (string) ($data['value'] ?? '')], 'key');

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

        EnvLimits::assertFits($app, $parsed['vars'], 'env');

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
     * One saved value, for its owner to read and edit. Behind a recent password; values are
     * never part of a page's props.
     */
    public function reveal(App $app, string $key, AuditLogger $audit): JsonResponse
    {
        $var = $app->envVars()->where('key', $key)->firstOrFail();

        $audit->record('app.env_revealed', null, ['app' => $app->slug, 'key' => $key], $app);

        return response()->json(['key' => $var->key, 'value' => (string) $var->value], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * Change the value of one variable that already exists (adding goes through `store`).
     */
    public function update(Request $request, App $app, string $key, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['value' => ['present', 'nullable', 'string', 'max:32768']]);
        $var = $app->envVars()->where('key', $key)->firstOrFail();

        if ((string) $var->value === (string) ($data['value'] ?? '')) {
            return back()->with('status', __('Nothing changed.'));
        }

        $var->update(['value' => (string) ($data['value'] ?? '')]);
        $audit->record('app.env_changed', null, ['app' => $app->slug, 'key' => $key], $app);

        return back()->with('status', __(':key saved. Apply changes to use it.', ['key' => $key]));
    }

    /**
     * Every variable as a .env file. The file holds secrets, so it needs a recent password and is
     * audited as dangerous; it is named after the app so two apps' files cannot be mixed up.
     */
    public function download(Request $request, App $app, AuditLogger $audit): Response
    {
        $vars = $app->envVars()->orderBy('key')->get();
        $lines = $vars->map(fn (AppEnvVar $var) => $var->key.'='.$this->quote((string) $var->value))->all();

        $audit->record('app.env_downloaded', $request->user(), ['app' => $app->slug, 'keys' => $vars->pluck('key')->all()], $app);

        return response($lines === [] ? '' : implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=".env.'.$app->slug.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A value as one .env line: plain when it is safe, single quotes (taken literally) when it has
     * other characters, double quotes with escapes only when it has a quote or line break.
     */
    private function quote(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_@%+=:,.\/-]*$/', $value) === 1) {
            return $value;
        }

        if (! str_contains($value, "'") && ! str_contains($value, "\n") && ! str_contains($value, "\r")) {
            return "'".$value."'";
        }

        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', '$' => '\\$']).'"';
    }

    public function clear(App $app, AuditLogger $audit): RedirectResponse
    {
        $keys = $app->envVars()->pluck('key')->all();
        $app->envVars()->delete();
        $audit->record('app.env_cleared', null, ['app' => $app->slug, 'keys' => $keys], $app);

        return back()->with('status', __('All :count variable(s) removed. Apply the changes to stop using them.', ['count' => count($keys)]));
    }
}
