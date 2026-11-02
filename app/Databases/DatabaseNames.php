<?php

namespace App\Databases;

use App\Models\App;
use App\Models\DatabaseServer;
use Illuminate\Support\Str;

/**
 * The names a database server, a database and its user may have. The agent
 * checks every one of them again before it reaches a database.
 */
class DatabaseNames
{
    /** Names the engines keep for themselves. */
    private const RESERVED_DATABASES = ['postgres', 'template0', 'template1', 'mysql', 'information_schema', 'performance_schema', 'sys'];

    private const RESERVED_USERS = ['root', 'postgres', 'mysql', 'mariadb', 'sys', 'admin', 'replication', 'healthcheck'];

    /**
     * @throws DatabaseException
     */
    public static function database(string $name): string
    {
        $name = trim($name);

        if (preg_match('/^[a-z][a-z0-9_]{0,30}$/', $name) !== 1) {
            throw new DatabaseException('Use lowercase letters, digits and underscores, starting with a letter, up to 31 characters.');
        }

        if (in_array($name, self::RESERVED_DATABASES, true) || self::reservedUser($name)) {
            throw new DatabaseException("{$name} is a name the database server keeps for itself.");
        }

        return $name;
    }

    private static function reservedUser(string $name): bool
    {
        return in_array($name, self::RESERVED_USERS, true) || str_starts_with($name, 'pg_') || str_starts_with($name, 'mysql');
    }

    /**
     * A server's slug: it names the container, network and data folder. It ends in
     * a letter, so a server's container can never be mistaken for an app's
     * (apps' containers end in a release number), and it avoids the network
     * name of an app called db-<slug>.
     */
    public static function serverSlug(string $name): string
    {
        $base = trim(Str::limit(Str::slug($name), 24, ''), '-');
        $base = preg_replace('/[^a-z]+$/', '', $base) ?? '';
        $base = strlen($base) < 2 ? 'database' : $base;

        $slug = $base;
        $n = 1;

        while (DatabaseServer::query()->where('slug', $slug)->exists() || App::query()->where('slug', 'db-'.$slug)->exists()) {
            $slug = $base.'-'.self::letters($n++);
        }

        return $slug;
    }

    /** 1 gives a, 26 gives z, 27 gives aa: a counter that never ends in a digit. */
    private static function letters(int $n): string
    {
        $out = '';

        for (; $n > 0; $n = intdiv($n - 1, 26)) {
            $out = chr(97 + ($n - 1) % 26).$out;
        }

        return $out;
    }

    /**
     * The names of the environment variables for a link: DATABASE gives DATABASE_URL and so on.
     *
     * @throws DatabaseException
     */
    public static function envPrefix(string $prefix): string
    {
        $prefix = strtoupper(trim($prefix));

        if (preg_match('/^[A-Z][A-Z0-9_]{0,30}$/', $prefix) !== 1) {
            throw new DatabaseException('Use capital letters, digits and underscores, starting with a letter.');
        }

        if (in_array($prefix, ['PORT', 'PATH', 'HOME', 'USER', 'HOSTNAME', 'ASPNETCORE'], true)) {
            throw new DatabaseException("{$prefix} would replace an important variable. Choose another name.");
        }

        return $prefix;
    }
}
