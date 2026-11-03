<?php

namespace App\Presenters;

use App\Databases\DatabaseTools;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;

/**
 * Shapes database servers and databases for the pages. Passwords and
 * connection strings are never part of it: they are shown on request only.
 */
class DatabasePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function server(DatabaseServer $server): array
    {
        return [
            'slug' => $server->slug,
            'name' => $server->name,
            'engine' => $server->engine->value,
            'engine_label' => $server->engine->label(),
            // The tool a database of this server opens in: phpMyAdmin for MySQL and MariaDB, Adminer for PostgreSQL.
            'tool' => DatabaseTools::toolName($server),
            'version' => $server->version,
            'status' => $server->status->value,
            'status_label' => $server->status->label(),
            'error' => $server->error,
            'host' => $server->slug,
            'port' => $server->engine->port(),
            'databases_count' => $server->databases()->count(),
            'created_at' => $server->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function database(ManagedDatabase $database): array
    {
        return [
            'id' => $database->id,
            'name' => $database->name,
            'username' => $database->username,
            'apps' => $database->links->map(fn ($link) => ['slug' => $link->app->slug, 'name' => $link->app->name])->values()->all(),
            'restore' => $database->restore_status === null ? null : ['status' => $database->restore_status, 'from' => $database->restore_from, 'error' => $database->restore_error],
            'created_at' => $database->created_at?->toIso8601String(),
        ];
    }
}
