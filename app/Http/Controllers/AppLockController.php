<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\App;
use App\Models\AppVolume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Locking an app or one of its volumes. A lock only forbids deleting: it is a
 * guard against a slip, so anyone who may change the app can lift it, and every
 * change is written to the audit log.
 */
class AppLockController extends Controller
{
    public function app(Request $request, App $app, AuditLogger $audit): RedirectResponse
    {
        $locked = $request->validate(['locked' => ['required', 'boolean']])['locked'];

        if ($locked === $app->isLocked()) {
            return back();
        }

        $app->forceFill(['locked_at' => $locked ? now() : null])->save();
        $audit->record($locked ? 'app.locked' : 'app.unlocked', $request->user(), ['app' => $app->slug], $app);

        return back()->with('status', $locked ? __(':name is locked. It cannot be deleted until you unlock it.', ['name' => $app->name]) : __(':name is unlocked.', ['name' => $app->name]));
    }

    public function volume(Request $request, App $app, AppVolume $volume, AuditLogger $audit): RedirectResponse
    {
        $locked = $request->validate(['locked' => ['required', 'boolean']])['locked'];

        if ($locked === $volume->isLocked()) {
            return back();
        }

        $volume->forceFill(['locked_at' => $locked ? now() : null])->save();
        $audit->record($locked ? 'app.volume_locked' : 'app.volume_unlocked', $request->user(), ['app' => $app->slug, 'path' => $volume->path], $app);

        return back()->with('status', $locked ? __(':path is locked. It cannot be removed until you unlock it.', ['path' => $volume->path]) : __(':path is unlocked.', ['path' => $volume->path]));
    }
}
