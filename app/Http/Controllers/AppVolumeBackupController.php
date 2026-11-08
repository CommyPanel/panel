<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Backups\BackupFailed;
use App\Backups\VolumeBackups;
use App\Jobs\BackupVolumeJob;
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

        return response()->json([
            'backups' => $backups->all($app),
            'pending' => (object) $pending,
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
