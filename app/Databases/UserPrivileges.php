<?php

namespace App\Databases;

use App\Enums\DatabaseEngine;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;

/**
 * What the users of a database server may do. By default an ordinary user has every privilege on its own
 * database and nothing else; the user of the first database of a server made for one app is the server's
 * administrator and may also create and drop databases and users on the whole server. A person can narrow
 * what a user may do (see ManageDatabases::updatePrivileges); the wanted set is kept on the database so this
 * shows the truth and a restore can put it back. This is knowledge, not a live query, so it needs no running
 * server.
 */
final class UserPrivileges
{
    /** What MySQL and MariaDB let a user hold on one database. */
    public const DATABASE_KEYS = [
        'select', 'insert', 'update', 'delete',
        'create', 'drop', 'alter', 'index', 'references', 'create_temporary_tables', 'lock_tables',
        'create_view', 'show_view', 'create_routine', 'alter_routine', 'execute', 'trigger', 'event',
    ];

    /** What only a server-wide user can hold (besides the right to pass privileges on). */
    public const SERVER_KEYS = ['create_user', 'show_databases'];

    /** @var array<string, list<string>> */
    public const PRESETS = [
        'full' => self::DATABASE_KEYS,
        'read_write' => ['select', 'insert', 'update', 'delete', 'lock_tables', 'execute', 'create_temporary_tables', 'show_view'],
        'read_only' => ['select', 'show_view', 'execute'],
    ];

    /**
     * The privileges worth showing for an engine, in the order of the table.
     *
     * @return list<string>
     */
    public static function keys(DatabaseEngine $engine): array
    {
        return $engine === DatabaseEngine::Postgres
            ? ['login', 'database_owner', 'create_databases', 'create_roles']
            : [...self::DATABASE_KEYS, ...self::SERVER_KEYS, 'grant_option'];
    }

    /**
     * The privileges a person may switch on or off for one kind of user. An ordinary user's server-wide rights
     * are never offered: they would break the isolation of the apps that share a server.
     *
     * @return list<string>
     */
    public static function editableKeys(DatabaseEngine $engine, bool $administrator): array
    {
        if ($engine === DatabaseEngine::Postgres) {
            return $administrator ? ['create_databases', 'create_roles'] : [];
        }

        return $administrator ? [...self::DATABASE_KEYS, ...self::SERVER_KEYS, 'grant_option'] : self::DATABASE_KEYS;
    }

    /** The name the agent knows a privilege by (`create_temporary_tables` is `CREATE TEMPORARY TABLES`). */
    public static function agentName(string $key): string
    {
        return match ($key) {
            'create_databases' => 'CREATEDB',
            'create_roles' => 'CREATEROLE',
            default => strtoupper(str_replace('_', ' ', $key)),
        };
    }

    /**
     * What a user holds when nobody changed it.
     *
     * @return array<string, bool> privilege => granted
     */
    public static function defaults(DatabaseEngine $engine, bool $wholeServer): array
    {
        $granted = [];

        foreach (self::keys($engine) as $key) {
            $granted[$key] = $wholeServer || ! in_array($key, ['create_databases', 'create_roles', ...self::SERVER_KEYS, 'grant_option'], true);
        }

        return $granted;
    }

    /**
     * What one user holds: the default, with what a person changed on top.
     *
     * @param  array{privileges: list<string>, grant_option: bool}|null  $stored
     * @return array<string, bool> privilege => granted
     */
    public static function for(DatabaseEngine $engine, bool $wholeServer, ?array $stored = null): array
    {
        $granted = self::defaults($engine, $wholeServer);

        if ($stored === null) {
            return $granted;
        }

        foreach (self::editableKeys($engine, $wholeServer) as $key) {
            $granted[$key] = $key === 'grant_option' ? $stored['grant_option'] : in_array($key, $stored['privileges'], true);
        }

        return $granted;
    }

    /** Whether this database's user is the administrator of a server made for one app. */
    public static function isAdministrator(ManagedDatabase $database): bool
    {
        $server = $database->server;

        return $server->dedicated_app_id !== null && $server->databases()->orderBy('id')->value('id') === $database->id;
    }

    /**
     * For the server's page: its users, the scope of each, what each may do and what may be changed.
     *
     * @return array{privileges: list<string>, whole_server: bool, presets: array<string, list<string>>, users: list<array{id: int, user: string, database: string, scope: string, granted: array<string, bool>, editable_keys: list<string>, custom: bool}>}
     */
    public static function present(DatabaseServer $server): array
    {
        $administrator = $server->dedicated_app_id === null ? null : $server->databases()->orderBy('id')->value('id');
        $users = [];

        foreach ($server->databases()->orderBy('name')->get(['id', 'name', 'username', 'privileges']) as $database) {
            $whole = $administrator !== null && $database->id === $administrator;

            $users[] = [
                'id' => $database->id,
                'user' => $database->username,
                'database' => $database->name,
                'scope' => $whole ? 'server' : 'database',
                'granted' => self::for($server->engine, $whole, $database->privileges),
                'editable_keys' => self::editableKeys($server->engine, $whole),
                'custom' => $database->privileges !== null,
            ];
        }

        return [
            'privileges' => self::keys($server->engine),
            'whole_server' => in_array('server', array_column($users, 'scope'), true),
            'presets' => $server->engine === DatabaseEngine::Postgres ? [] : self::PRESETS,
            'users' => $users,
        ];
    }
}
