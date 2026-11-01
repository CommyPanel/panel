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

        return back()->with('status', "{$volume->path} will be kept between deployments, starting with the next one.");
    }

    public function destroy(Request $request, App $app, AppVolume $volume, AuditLogger $audit): RedirectResponse
    {
        if ($volume->isLocked()) {
            return back()->withErrors(['volume' => 'Volume is locked and cannot be removed. Unlock it first.']);
        }

        $volume->delete();

        $audit->record('app.volume_removed', $request->user(), ['app' => $app->slug, 'path' => $volume->path, 'name' => $volume->name], $app);

        return back()->with('status', "{$volume->path} is no longer mounted from the next deployment. Its files stay on the server.");
    }
}
