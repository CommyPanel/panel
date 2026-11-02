<?php

namespace App\Databases;

use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;
use Illuminate\Support\Str;

/**
 * The databases on a server. Each has one user that can use it and nothing
 * else, with a generated password that is kept encrypted.
 */
class ManageDatabases
{
    public function __construct(private readonly HostDriver $host) {}

    /**
     * @throws DatabaseException
     */
    public function create(DatabaseServer $server, string $name): ManagedDatabase
    {
        $name = DatabaseNames::database($name);

        if (! $server->isRunning()) {
            throw new DatabaseException('The server is not running. Start it first.');
        }

        if ($server->databases()->where('name', $name)->exists()) {
            throw new DatabaseException("There is already a database called {$name} on this server.");
        }

        $password = Str::random(32);

        $this->attempt(fn () => $this->host->createDatabase($server->target(), $name, $name, $password), 'create the database');

        /** @var ManagedDatabase */
        return $server->databases()->create(['name' => $name, 'username' => $name, 'password' => $password]);
    }

    /**
     * @throws DatabaseException
     */
    public function drop(ManagedDatabase $database): void
    {
        $server = $database->server;
        $apps = $this->appsUsing($database);

        if ($apps !== []) {
            throw new DatabaseException('Apps still use this database ('.implode(', ', $apps).'). Remove their links first.');
        }

        if (! $server->isRunning()) {
            throw new DatabaseException('The server is not running. Start it first.');
        }

        $this->attempt(fn () => $this->host->dropDatabase($server->target(), $database->name, $database->username), 'drop the database');

        $database->delete();
    }

    /**
     * Give the user a new password. Linked apps keep using the old one until they
     * are deployed again, which is why the apps are returned.
     *
     * @return list<string> names of the linked apps that need a new deployment
     *
     * @throws DatabaseException
     */
    public function rotatePassword(ManagedDatabase $database): array
    {
        $server = $database->server;

        if (! $server->isRunning()) {
            throw new DatabaseException('The server is not running. Start it first.');
        }

        $password = Str::random(32);

        $this->attempt(fn () => $this->host->setDatabasePassword($server->target(), $database->username, $password), 'change the password');

        $database->forceFill(['password' => $password])->save();

        return $this->appsUsing($database);
    }

    /**
     * @return list<string>
     */
    public function appsUsing(ManagedDatabase $database): array
    {
        return array_values($database->links()->with('app')->get()->map(fn ($link) => $link->app->name)->all());
    }

    /**
     * @throws DatabaseException
     */
    private function attempt(callable $action, string $what): void
    {
        try {
            $action();
        } catch (HostOperationFailed $e) {
            throw new DatabaseException("Could not {$what}: {$e->getMessage()}", 0, $e);
        }
    }
}
