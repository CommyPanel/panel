<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Databases\DatabaseException;
use App\Databases\DatabaseTools;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Opening a database in phpMyAdmin or pgAdmin. The page opens in a new tab, starts the
 * tool, follows its progress and then moves itself on to the tool, already logged in.
 */
class DatabaseToolController extends Controller
{
    public function show(DatabaseServer $server, ManagedDatabase $database): Response
    {
        return Inertia::render('databases/tool', [
            'server' => ['slug' => $server->slug, 'name' => $server->name],
            'database' => ['id' => $database->id, 'name' => $database->name, 'user' => $database->username],
            'tool' => DatabaseTools::toolName($database),
            'idle_minutes' => DatabaseTools::IDLE_MINUTES,
        ]);
    }

    public function start(Request $request, DatabaseServer $server, ManagedDatabase $database, DatabaseTools $tools, AuditLogger $audit): JsonResponse
    {
        try {
            $session = $tools->start($database, $request);
        } catch (DatabaseException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $audit->record('database.tool_started', $request->user(), ['server' => $database->server->slug, 'database' => $database->name, 'tool' => $session->tool]);

        return response()->json(['session' => $session->id, 'stage' => $session->stage]);
    }

    public function status(DatabaseServer $server, ManagedDatabase $database, DatabaseTools $tools, string $session): JsonResponse
    {
        try {
            $found = $tools->status($database, $session);
        } catch (DatabaseException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        if ($found === null) {
            return response()->json(['gone' => true], 404);
        }

        return response()->json(['stage' => $found->stage, 'error' => $found->error]);
    }

    public function open(Request $request, DatabaseServer $server, ManagedDatabase $database, DatabaseTools $tools, AuditLogger $audit, string $session): JsonResponse
    {
        try {
            $url = $tools->openUrl($database, $session, $request);
        } catch (DatabaseException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $audit->record('database.tool_opened', $request->user(), ['server' => $database->server->slug, 'database' => $database->name, 'tool' => DatabaseTools::toolName($database)]);

        return response()->json(['url' => $url]);
    }

    public function stop(Request $request, DatabaseServer $server, ManagedDatabase $database, DatabaseTools $tools, AuditLogger $audit, string $session): JsonResponse
    {
        try {
            $tools->stop($database, $session);
        } catch (DatabaseException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        $audit->record('database.tool_closed', $request->user(), ['server' => $database->server->slug, 'database' => $database->name, 'tool' => DatabaseTools::toolName($database)]);

        return response()->json(['ok' => true]);
    }
}
