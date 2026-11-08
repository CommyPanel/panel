<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\AppVolumes;
use App\Models\App;
use App\Models\AppVolume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Which paths of an app's container are kept between deployments. A change
 * applies to the next deployment; removing a volume keeps its data on the host.
 */
class AppVolumeController extends Controller
{
    public function store(Request $request, App $app, AppVolumes $volumes, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:255']]);

        try {
            $volume = $volumes->add($app, $data['path']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['path' => $e->getMessage()]);
        }

        $audit->record('app.volume_added', $request->user(), ['app' => $app->slug, 'path' => $volume->path, 'name' => $volume->name], $app);

        return back()->with('status', __(':path will be kept between deployments once you apply the changes.', ['path' => $volume->path]));
    }

    public function destroy(Request $request, App $app, AppVolume $volume, AuditLogger $audit): RedirectResponse
    {
        if ($volume->isLocked()) {
            return back()->withErrors(['volume' => __('Volume is locked and cannot be removed. Unlock it first.')]);
        }

        $volume->delete();

        $audit->record('app.volume_removed', $request->user(), ['app' => $app->slug, 'path' => $volume->path, 'name' => $volume->name], $app);

        return back()->with('status', __(':path will no longer be mounted once you apply the changes. Its files stay on the server.', ['path' => $volume->path]));
    }

    /**
     * Switch the nightly archive of one volume on or off.
     */
    public function nightly(Request $request, App $app, AppVolume $volume, AuditLogger $audit): RedirectResponse
    {
        $on = $request->validate(['nightly' => ['required', 'boolean']])['nightly'];

        $volume->forceFill(['nightly_backup' => $on])->save();
        $audit->record($on ? 'app.volume_nightly_on' : 'app.volume_nightly_off', $request->user(), ['app' => $app->slug, 'path' => $volume->path, 'name' => $volume->name], $app);

        return back()->with('status', $on
            ? __(':path is archived every night.', ['path' => $volume->path])
            : __(':path is no longer archived every night.', ['path' => $volume->path]));
    }
}
