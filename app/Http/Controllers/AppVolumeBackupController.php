<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Backups\BackupFailed;
use App\Backups\VolumeBackups;
use App\Jobs\BackupVolumeJob;
use App\Jobs\RestoreVolumeJob;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Archives of an app's volumes. An archive holds every file of the volume, so
 * downloading or deleting one needs a recent password, and downloads are audited.
 */
class AppVolumeBackupController extends Controller
{
    public function index(App $app, VolumeBackups $backups): JsonResponse
    {
        $pending = [];

        foreach ($app->volumes()->pluck('name') as $volume) {
            $state = Cache::get(BackupVolumeJob::pendingKey($app->slug, $volume));

            if ($state !== null) {
                $pending[$volume] = $state === true ? ['running' => true] : $state;
            }
        }

        $restores = [];

        foreach ($app->volumes()->pluck('name') as $volume) {
            $state = Cache::get(RestoreVolumeJob::stateKey($app->slug, $volume));

            if ($state === true) {
                $restores[$volume] = ['running' => true];
            } elseif (is_array($state)) {
                $restores[$volume] = ($state['code'] ?? null) === 'unrecorded'
                    ? ['failed' => __('This backup was made before CommyPanel 0.8.0 recorded its backups, so it cannot be restored from here. Make a new backup and use that one.')]
                    : array_intersect_key($state, ['failed' => 1, 'done' => 1, 'at' => 1]);
            }
        }

        return response()->json([
            'backups' => $backups->all($app),
            'pending' => (object) $pending,
            'restores' => (object) $restores,
            'keep' => (int) config('commypanel.backups.keep_volumes'),
        ]);
    }

    public function store(App $app, string $name): RedirectResponse
    {
        abort_unless($app->volumes()->where('name', $name)->exists(), 404);

        $key = BackupVolumeJob::pendingKey($app->slug, $name);

        if (Cache::get($key) === true) {
            return back()->withErrors(['volume' => __('A backup of this volume is already running.')]);
        }

        Cache::put($key, true, now()->addHours(2));
        BackupVolumeJob::dispatch($app->id, $name);

        return back()->with('status', __('Backing up :name. It appears in the list when it is done.', ['name' => $name]));
    }

    public function download(Request $request, App $app, VolumeBackups $backups, AuditLogger $audit, string $file): BinaryFileResponse|RedirectResponse
    {
        try {
            $path = $backups->path($app, $file);
        } catch (BackupFailed $e) {
            return back()->withErrors(['volume' => $e->getMessage()]);
        }

        $audit->record('volume.backup_downloaded', $request->user(), ['app' => $app->slug, 'file' => $file]);

        return response()->download($path, $file, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Put an archive back over its volume. The app stops for a moment; a safety archive of
     * what the volume holds now is made first. The person typed the volume's name.
     */
    public function restore(Request $request, App $app, VolumeBackups $backups, AuditLogger $audit, string $file): RedirectResponse
    {
        $confirm = $request->validate(['confirm' => ['required', 'string', 'max:64']])['confirm'];

        try {
            $backups->path($app, $file);
        } catch (BackupFailed $e) {
            return back()->withErrors(['volume' => $e->getMessage()]);
        }

        $name = (string) preg_replace('/-\d{8}-\d{6}\.tar\.gz$/', '', $file);
        $volume = $app->volumes()->where('name', $name)->first();

        if ($volume === null) {
            return back()->withErrors(['volume' => __('This archive belongs to a volume the app no longer has.')]);
        }

        if ($confirm !== $volume->name) {
            return back()->withErrors(['volume' => __('Type the name of the volume to confirm.')]);
        }

        if ($app->isLocked() || $volume->isLocked()) {
            return back()->withErrors(['volume' => __('The app or this volume is locked. Unlock it before restoring over it.')]);
        }

        if ($app->isDeploying()) {
            return back()->withErrors(['volume' => __('Wait for the running deployment to finish first.')]);
        }

        $key = RestoreVolumeJob::stateKey($app->slug, $name);

        if (Cache::get($key) === true || Cache::get(BackupVolumeJob::pendingKey($app->slug, $name)) === true) {
            return back()->withErrors(['volume' => __('A backup or restore of this volume is already running.')]);
        }

        Cache::put($key, true, now()->addHours(3));
        RestoreVolumeJob::dispatch($app->id, $name, $file, $request->user()?->id);

        $audit->record('volume.restored', $request->user(), ['app' => $app->slug, 'volume' => $name, 'file' => $file], $app);

        return back()->with('status', __('Restoring :name. The app stops for a moment; the volume shows the result when it is done.', ['name' => $name]));
    }

    public function destroy(Request $request, App $app, VolumeBackups $backups, AuditLogger $audit, string $file): RedirectResponse
    {
        try {
            $backups->delete($app, $file);
        } catch (BackupFailed $e) {
            return back()->withErrors(['volume' => $e->getMessage()]);
        }

        $audit->record('volume.backup_deleted', $request->user(), ['app' => $app->slug, 'file' => $file]);

        return back()->with('status', __('Backup deleted.'));
    }
}
