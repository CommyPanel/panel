<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\ReleaseScripts;
use App\Models\App;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The deployment script: lines run by `sh -ec` in a container from the new image before a release goes
 * live. Saving it replaces the single release command, so the two can never both run. It changes what runs
 * inside the app's own container (no more than a deployment already does), and applies from the next deployment.
 */
class AppScriptController extends Controller
{
    public function update(Request $request, App $app, AuditLogger $audit): RedirectResponse
    {
        abort_unless(ReleaseScripts::supports($app->stack ?? $app->stack_hint), 404);

        $request->merge(['release_script' => ReleaseScripts::normalize($request->input('release_script'))]);

        $data = $request->validate([
            'release_script' => ['nullable', 'string', 'max:'.ReleaseScripts::MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail) {
                if (str_contains((string) $value, "\0")) {
                    $fail(__('The script holds a character that cannot be text.'));
                }
            }],
        ]);

        $script = $data['release_script'] ?? null;
        $before = $app->release_script;

        $app->forceFill(['release_script' => $script, 'release_command' => null])->save();

        // The script itself is not written to the audit log: people paste credentials into scripts.
        $audit->record('app.script_changed', $request->user(), [
            'app' => $app->slug,
            'lines_before' => self::lines($before),
            'lines_after' => self::lines($script),
        ], $app);

        return back()->with('status', __('Saved. The script runs from the next deployment.'));
    }

    private static function lines(?string $script): int
    {
        return $script === null ? 0 : substr_count($script, "\n");
    }
}
