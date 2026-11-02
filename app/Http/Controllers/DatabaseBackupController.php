<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Databases\DatabaseBackups;
use App\Databases\DatabaseException;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Dumps of a database. A dump holds the data itself (customers, orders), so
 * downloading one needs the manage permission and a recent password, and is
 * recorded; the names of the files can never point anywhere else.
 */
class DatabaseBackupController extends Controller
{
    public function store(Request $request, DatabaseServer $server, ManagedDatabase $database, DatabaseBackups $backups, AuditLogger $audit): RedirectResponse
    {
        try {
            $backup = $backups->create($database);
        } catch (DatabaseException $e) {
            return back()->withErrors(['database' => $e->getMessage()]);
        }

        $audit->record('database.backed_up', $request->user(), ['server' => $server->slug, 'database' => $database->name, 'file' => $backup['name']]);

        return back()->with('status', "{$database->name} was backed up.");
    }

    public function download(Request $request, DatabaseServer $server, DatabaseBackups $backups, AuditLogger $audit, string $name): BinaryFileResponse|RedirectResponse
    {
        try {
            $path = $backups->path($server, $name);
        } catch (DatabaseException $e) {
            return back()->withErrors(['database' => $e->getMessage()]);
        }

        $audit->record('database.backup_downloaded', $request->user(), ['server' => $server->slug, 'file' => $name]);

        return response()->download($path, $name, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, DatabaseServer $server, DatabaseBackups $backups, AuditLogger $audit, string $name): RedirectResponse
    {
        try {
            $backups->delete($server, $name);
        } catch (DatabaseException $e) {
            return back()->withErrors(['database' => $e->getMessage()]);
        }

        $audit->record('database.backup_deleted', $request->user(), ['server' => $server->slug, 'file' => $name]);

        return back()->with('status', 'Backup deleted.');
    }
}
