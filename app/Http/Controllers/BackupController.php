<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Backups\BackupFailed;
use App\Backups\PanelBackups;
use App\Backups\Remote\RemoteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function index(PanelBackups $backups, RemoteSettings $remote): Response
    {
        return Inertia::render('settings/backups', [
            'backups' => array_map(fn (array $b) => [
                'name' => $b['name'],
                'size' => $b['size'],
                'created_at' => date('c', $b['created']),
            ], $backups->all()),
            'keep' => (int) config('commypanel.backups.keep'),
            'folder' => $backups->directory(),
            'remote' => $remote->toArray(),
        ]);
    }

    public function store(Request $request, PanelBackups $backups, AuditLogger $audit): RedirectResponse
    {
        try {
            $backup = $backups->create();
        } catch (BackupFailed $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        $audit->record('backup.created', $request->user(), ['name' => $backup['name']]);

        return back()->with('status', 'Backup made.');
    }

    public function download(Request $request, PanelBackups $backups, AuditLogger $audit, string $name): BinaryFileResponse|RedirectResponse
    {
        try {
            $path = $backups->path($name);
        } catch (BackupFailed $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        $audit->record('backup.downloaded', $request->user(), ['name' => $name]);

        return response()->download($path, $name, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, PanelBackups $backups, AuditLogger $audit, string $name): RedirectResponse
    {
        try {
            $backups->delete($name);
        } catch (BackupFailed $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        $audit->record('backup.deleted', $request->user(), ['name' => $name]);

        return back()->with('status', 'Backup deleted.');
    }
}
