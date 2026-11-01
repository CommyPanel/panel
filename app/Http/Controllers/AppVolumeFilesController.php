<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Host\Data\VolumeEntry;
use App\Host\Data\VolumeUsage;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppVolume;
use App\Presenters\AppPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Looking into an app's volumes: how much each uses, and a read-only file
 * browser. Volume files are written by the app and often hold secrets (a
 * database, uploads), so this is its own permission and downloads are audited.
 * Deleting the data of a volume that is no longer attached is a settings change.
 */
class AppVolumeFilesController extends Controller
{
    public function index(App $app, HostDriver $host): JsonResponse
    {
        try {
            $onDisk = collect($host->listVolumes($app->slug))->keyBy(fn (VolumeUsage $v) => $v->name);
        } catch (HostOperationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $attached = $app->volumes()->orderBy('id')->get();

        $rows = $attached->map(fn (AppVolume $volume) => $this->row($volume->name, $volume->path, $onDisk->get($volume->name), $volume));

        $detached = $onDisk->reject(fn (VolumeUsage $v) => $attached->contains('name', $v->name))
            ->map(fn (VolumeUsage $v) => $this->row($v->name, null, $v))
            ->values();

        $all = $rows->concat($detached)->values();

        return response()->json([
            'volumes' => $all->all(),
            'total_bytes' => $all->sum('bytes'),
            'running' => $app->active_deployment_id !== null,
        ]);
    }

    /**
     * The page for browsing one volume: where it is mounted, its totals, and the
     * files and folders of the folder being looked at.
     */
    public function show(Request $request, App $app, string $name, HostDriver $host): InertiaResponse
    {
        $path = $this->path($request);
        $attached = $app->volumes()->where('name', $name)->first();

        try {
            $usage = collect($host->listVolumes($app->slug))->first(fn (VolumeUsage $v) => $v->name === $name);
            $error = null;
        } catch (HostOperationFailed $e) {
            $usage = null;
            $error = $e->getMessage();
        }

        // A name that is neither one of the app's volumes nor a folder on disk.
        abort_if($attached === null && $usage === null && $error === null, 404);

        $entries = [];
        $truncated = false;

        if ($usage !== null) {
            try {
                $listing = $host->browseVolume($app->slug, $name, $path);
                $entries = array_map(fn (VolumeEntry $e) => [
                    'name' => $e->name,
                    'kind' => $e->kind,
                    'size' => $e->size,
                    'modified' => $e->modified === 0 ? null : date('c', $e->modified),
                ], $listing->entries);
                $truncated = $listing->truncated;
            } catch (HostOperationFailed $e) {
                $error = $e->getMessage();
            }
        }

        return Inertia::render('apps/volume', [
            'app' => AppPresenter::summary($app),
            'volume' => [
                'id' => $attached?->id,
                'name' => $name,
                'path' => $attached?->path,
                'attached' => $attached !== null,
                'locked' => $attached !== null && $attached->isLocked(),
                // An attached volume has no folder until a container using it has started.
                'exists' => $usage !== null,
                'bytes' => $usage === null ? 0 : $usage->bytes,
                'files' => $usage === null ? 0 : $usage->files,
                'partial' => $usage !== null && $usage->partial,
            ],
            'path' => $path,
            'entries' => $entries,
            'folder' => [
                'files' => count(array_filter($entries, fn (array $e) => $e['kind'] === 'file')),
                'folders' => count(array_filter($entries, fn (array $e) => $e['kind'] === 'dir')),
                'other' => count(array_filter($entries, fn (array $e) => ! in_array($e['kind'], ['file', 'dir'], true))),
                'truncated' => $truncated,
            ],
            'error' => $error,
        ]);
    }

    public function file(Request $request, App $app, string $name, HostDriver $host): JsonResponse
    {
        $path = $this->path($request);

        try {
            $preview = $host->previewVolumeFile($app->slug, $name, $path);
        } catch (HostOperationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json([
            'path' => $path,
            'size' => $preview->size,
            'content' => $preview->content,
            'binary' => $preview->binary,
            'truncated' => $preview->truncated,
        ]);
    }

    public function download(Request $request, App $app, string $name, HostDriver $host, AuditLogger $audit): Response
    {
        $path = $this->path($request);

        try {
            $content = $host->readVolumeFile($app->slug, $name, $path);
        } catch (HostOperationFailed $e) {
            return response($e->getMessage(), 502);
        }

        $audit->record('app.volume_file_downloaded', $request->user(), ['app' => $app->slug, 'volume' => $name, 'path' => $path], $app);

        return response($content, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($path).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Delete the files of a volume the app no longer mounts. The agent refuses
     * while any container still has it mounted.
     */
    public function destroyData(Request $request, App $app, string $name, HostDriver $host, AuditLogger $audit): RedirectResponse
    {
        if ($app->volumes()->where('name', $name)->exists()) {
            return back()->withErrors(['volume' => 'This volume is still attached to the app. Remove it from Settings first.']);
        }

        try {
            $host->removeVolumes($app->slug, $name);
        } catch (HostOperationFailed $e) {
            return back()->withErrors(['volume' => $e->getMessage()]);
        }

        $audit->record('app.volume_data_deleted', $request->user(), ['app' => $app->slug, 'volume' => $name], $app);

        return back()->with('status', 'The files of that volume are deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $name, ?string $path, ?VolumeUsage $usage, ?AppVolume $volume = null): array
    {
        return [
            'id' => $volume?->id,
            'locked' => $volume !== null && $volume->isLocked(),
            'name' => $name,
            'path' => $path,
            'attached' => $path !== null,
            // An attached volume has no folder until a container using it has started.
            'exists' => $usage !== null,
            'bytes' => $usage === null ? 0 : $usage->bytes,
            'files' => $usage === null ? 0 : $usage->files,
            'partial' => $usage !== null && $usage->partial,
        ];
    }

    private function path(Request $request): string
    {
        $path = $request->query('path', '');

        return is_string($path) ? substr(trim($path, '/'), 0, 1024) : '';
    }

    private function filename(string $path): string
    {
        $base = basename($path);

        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?: 'file';
    }
}
