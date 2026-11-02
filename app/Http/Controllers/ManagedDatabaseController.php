<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Databases\DatabaseConnection;
use App\Databases\DatabaseException;
use App\Databases\ManageDatabases;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ManagedDatabaseController extends Controller
{
    public function store(Request $request, DatabaseServer $server, ManageDatabases $databases, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        try {
            $database = $databases->create($server, $data['name']);
        } catch (DatabaseException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        $audit->record('database.created', $request->user(), ['server' => $server->slug, 'database' => $database->name]);

        return back()->with('status', "Database {$database->name} created. Link it to an app to give the app its connection details.");
    }

    public function destroy(Request $request, DatabaseServer $server, ManagedDatabase $database, ManageDatabases $databases, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:'.$database->name]], ['confirm.in' => 'Type the database name exactly to confirm.']);

        $name = $database->name;

        try {
            $databases->drop($database);
        } catch (DatabaseException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        $audit->record('database.dropped', $request->user(), ['server' => $server->slug, 'database' => $name]);

        return back()->with('status', "Database {$name} and its data are deleted.");
    }

    public function password(Request $request, DatabaseServer $server, ManagedDatabase $database, ManageDatabases $databases, AuditLogger $audit): RedirectResponse
    {
        try {
            $apps = $databases->rotatePassword($database);
        } catch (DatabaseException $e) {
            return back()->withErrors(['database' => $e->getMessage()]);
        }

        $audit->record('database.password_changed', $request->user(), ['server' => $server->slug, 'database' => $database->name, 'apps' => $apps]);

        return back()->with('status', 'The password was changed.'.($apps === [] ? '' : ' Deploy '.implode(', ', $apps).' again so it uses the new one.'));
    }

    /**
     * The connection details, with the password. Behind its own permission and
     * a recent password confirmation, and every look is recorded.
     */
    public function credentials(Request $request, DatabaseServer $server, ManagedDatabase $database, AuditLogger $audit): JsonResponse
    {
        $connection = DatabaseConnection::for($database);

        $audit->record('database.credentials_viewed', $request->user(), ['server' => $server->slug, 'database' => $database->name]);

        return response()->json([
            'host' => $connection->host,
            'port' => $connection->port,
            'name' => $connection->name,
            'user' => $connection->user,
            'password' => $connection->password,
            'url' => $connection->url,
        ])->header('Cache-Control', 'no-store');
    }
}
