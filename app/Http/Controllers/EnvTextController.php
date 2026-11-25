<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\AppEnvironment;
use App\Deploy\EnvText;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A Laravel app's variables as one .env text (see AppEnvironment). Reading it needs a recent password
 * and is audited, and the text is never part of a page's props; saving applies the difference to the
 * variables and records only the names that changed.
 */
class EnvTextController extends Controller
{
    public function show(Request $request, App $app, AppEnvironment $env, AuditLogger $audit): JsonResponse
    {
        abort_unless(AppEnvironment::usesText($app), 404);

        $text = $env->text($app);
        $audit->record('app.env_revealed', $request->user(), ['app' => $app->slug, 'count' => count(EnvText::parse($text)['vars'])], $app);

        return response()->json(['text' => $text], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function update(Request $request, App $app, AppEnvironment $env, AuditLogger $audit): RedirectResponse
    {
        abort_unless(AppEnvironment::usesText($app), 404);

        $data = $request->validate(['text' => ['present', 'nullable', 'string', 'max:400000']]);
        $diff = $env->replaceText($app, (string) ($data['text'] ?? ''));
        $count = count($diff['added']) + count($diff['changed']) + count($diff['removed']);

        if ($count > 0) {
            $audit->record('app.env_changed', $request->user(), ['app' => $app->slug, ...$diff], $app);
        }

        return back()->with('status', $count > 0
            ? __(':count variable(s) changed. Apply changes to use them.', ['count' => $count])
            : __('Saved. No variable changed.'));
    }
}
