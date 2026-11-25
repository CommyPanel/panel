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
     * @param  bool  $admin  the user may also create and drop databases and users on the whole server (only for a server made for one app)
     *
     * @throws DatabaseException
     */
    public function create(DatabaseServer $server, string $name, bool $admin = false): ManagedDatabase
    {
        $name = DatabaseNames::database($name);

        if (! $server->isRunning()) {
            throw new DatabaseException(__('The server is not running. Start it first.'));
        }

        if ($server->databases()->where('name', $name)->exists()) {
            throw new DatabaseException(__('There is already a database called :name on this server.', ['name' => $name]));
        }

        $password = Str::random(32);

        $this->attempt(fn () => $this->host->createDatabase($server->target(), $name, $name, $password, $admin), __('Could not create the database: :reason'));

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

        if ($database->restore_status === RestoreDatabase::RESTORING) {
            throw new DatabaseException(__('A backup is still being loaded into this database. Wait for it to finish.'));
        }

        if ($apps !== []) {
            throw new DatabaseException(__('Apps still use this database (:apps). Remove their links first.', ['apps' => implode(', ', $apps)]));
        }

        if (! $server->isRunning()) {
            throw new DatabaseException(__('The server is not running. Start it first.'));
        }

        $this->attempt(fn () => $this->host->dropDatabase($server->target(), $database->name, $database->username), __('Could not drop the database: :reason'));

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
            throw new DatabaseException(__('The server is not running. Start it first.'));
        }

        $password = Str::random(32);

        $this->attempt(fn () => $this->host->setDatabasePassword($server->target(), $database->username, $password), __('Could not change the password: :reason'));

        $database->forceFill(['password' => $password])->save();

        return $this->appsUsing($database);
    }

    /**
     * Change what the database's user may do. An ordinary user can only be narrowed on its own database
     * (MySQL and MariaDB); the administrator of a server made for one app is changed on the whole server.
     * The wanted set is kept so it can be shown and put back after a restore.
     *
     * @param  list<string>  $keys  the privileges to hold (UserPrivileges keys); `grant_option` is one of them for the administrator
     * @return array{scope: string, added: list<string>, removed: list<string>}
     *
     * @throws DatabaseException
     */
    public function updatePrivileges(ManagedDatabase $database, array $keys): array
    {
        $server = $database->server;
        $administrator = UserPrivileges::isAdministrator($database);
        $editable = UserPrivileges::editableKeys($server->engine, $administrator);

        if ($editable === []) {
            throw new DatabaseException(__('The permissions of this user cannot be changed.'));
        }

        $keys = array_values(array_unique($keys));

        if (array_diff($keys, $editable) !== []) {
            throw new DatabaseException(__('That permission cannot be given to this user.'));
        }

        if (! $server->isRunning()) {
            throw new DatabaseException(__('The server is not running. Start it first.'));
        }

        if ($database->restore_status !== null) {
            throw new DatabaseException(__('A backup is being loaded into this database. Wait for it to finish.'));
        }

        $before = UserPrivileges::for($server->engine, $administrator, $database->privileges);
        $wanted = $this->wanted($before, $editable, $keys);
        $grantOption = $wanted['grant_option'] ?? false;

        $this->applyPrivileges($database, $administrator, $wanted);

        $default = UserPrivileges::defaults($server->engine, $administrator);

        $database->forceFill(['privileges' => $wanted === $default ? null : [
            'privileges' => array_values(array_filter($editable, fn (string $key) => $key !== 'grant_option' && $wanted[$key])),
            'grant_option' => $grantOption,
        ]])->save();

        $changed = fn (bool $now) => array_values(array_filter(array_keys($wanted), fn (string $key) => $wanted[$key] === $now && $before[$key] !== $now));

        return ['scope' => $administrator ? 'server' : 'database', 'added' => $changed(true), 'removed' => $changed(false)];
    }

    /**
     * Put a user's changed (or administrator) rights back, for example after its database was restored over
     * (the restore makes the user again, with the ordinary rights).
     *
     * @throws DatabaseException
     */
    public function reapplyPrivileges(ManagedDatabase $database): void
    {
        $administrator = UserPrivileges::isAdministrator($database);

        if ($database->privileges === null && ! $administrator) {
            return;
        }

        $this->applyPrivileges($database, $administrator, UserPrivileges::for($database->server->engine, $administrator, $database->privileges));
    }

    /**
     * @param  array<string, bool>  $before  what the user holds now
     * @param  list<string>  $editable
     * @param  list<string>  $keys  what it should hold
     * @return array<string, bool>
     */
    private function wanted(array $before, array $editable, array $keys): array
    {
        $wanted = $before;

        foreach ($editable as $key) {
            $wanted[$key] = in_array($key, $keys, true);
        }

        return $wanted;
    }

    /**
     * @param  array<string, bool>  $granted
     *
     * @throws DatabaseException
     */
    private function applyPrivileges(ManagedDatabase $database, bool $administrator, array $granted): void
    {
        $server = $database->server;
        $names = array_map(UserPrivileges::agentName(...), array_values(array_filter(
            UserPrivileges::editableKeys($server->engine, $administrator),
            fn (string $key) => $key !== 'grant_option' && ($granted[$key] ?? false),
        )));

        $this->attempt(fn () => $this->host->setDatabasePrivileges(
            $server->target(), $database->name, $database->username, $administrator ? 'server' : 'database', $names, $administrator && ($granted['grant_option'] ?? false),
        ), __('Could not change the permissions: :reason'));
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
    /**
     * @param  string  $failure  the sentence to show if it fails, with `:reason` for what the host said
     */
    private function attempt(callable $action, string $failure): void
    {
        try {
            $action();
        } catch (HostOperationFailed $e) {
            throw new DatabaseException(strtr($failure, [':reason' => $e->getMessage()]), 0, $e);
        }
    }
}
