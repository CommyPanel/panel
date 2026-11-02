<?php

namespace App\Databases;

use App\Enums\DatabaseEngine;
use App\Enums\DatabaseServerStatus;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\ProvisionDatabaseServerJob;
use App\Models\DatabaseServer;
use Illuminate\Support\Str;

/**
 * Creating, controlling and deleting database servers. The panel decides what
 * should happen; the agent runs the container.
 */
class DatabaseServers
{
    public function __construct(private readonly HostDriver $host, private readonly DatabaseBackups $backups) {}

    /**
     * Register a server and start it in the background.
     *
     * @throws DatabaseException
     */
    public function create(string $name, DatabaseEngine $engine, string $version): DatabaseServer
    {
        if (! in_array($version, $engine->versions(), true)) {
            throw new DatabaseException("{$engine->label()} {$version} is not one of the versions offered.");
        }

        $server = DatabaseServer::query()->create([
            'name' => trim($name),
            'slug' => DatabaseNames::serverSlug($name),
            'engine' => $engine,
            'version' => $version,
            'status' => DatabaseServerStatus::Provisioning,
            // Letters and digits only: the agent accepts nothing else, so it can never break the SQL.
            'root_password' => Str::random(32),
        ]);

        ProvisionDatabaseServerJob::dispatch($server->id);

        return $server;
    }

    /**
     * Try again after a failed start.
     *
     * @throws DatabaseException
     */
    public function retry(DatabaseServer $server): void
    {
        if ($server->status !== DatabaseServerStatus::Failed) {
            throw new DatabaseException('Only a server that failed to start can be started again this way.');
        }

        $server->forceFill(['status' => DatabaseServerStatus::Provisioning, 'error' => null])->save();
        ProvisionDatabaseServerJob::dispatch($server->id);
    }

    /**
     * @throws DatabaseException
     */
    public function start(DatabaseServer $server): void
    {
        $this->requireInstalled($server);
        $this->attempt(fn () => $this->host->startContainer($server->containerName()), 'start');
        $server->forceFill(['status' => DatabaseServerStatus::Running])->save();
    }

    /**
     * @throws DatabaseException
     */
    public function stop(DatabaseServer $server): void
    {
        $this->requireInstalled($server);
        $this->attempt(fn () => $this->host->stopContainer($server->containerName()), 'stop');
        $server->forceFill(['status' => DatabaseServerStatus::Stopped])->save();
    }

    /**
     * @throws DatabaseException
     */
    public function restart(DatabaseServer $server): void
    {
        $this->requireInstalled($server);
        $this->attempt(fn () => $this->host->restartContainer($server->containerName()), 'restart');
        $server->forceFill(['status' => DatabaseServerStatus::Running])->save();
    }

    /**
     * Delete a server with all its databases and their files. Refused while an
     * app is linked to one of its databases.
     *
     * @throws DatabaseException
     */
    public function delete(DatabaseServer $server): void
    {
        $linked = $server->databases()->whereHas('links')->with('links.app')->get();

        if ($linked->isNotEmpty()) {
            $apps = $linked->flatMap(fn ($db) => $db->links->map(fn ($l) => $l->app->name))->unique()->implode(', ');

            throw new DatabaseException("Apps still use this server's databases ({$apps}). Remove their links first.");
        }

        $this->attempt(fn () => $this->host->removeContainer($server->containerName()), 'remove');

        try {
            $this->host->removeNetwork($server->networkName());
        } catch (HostOperationFailed) {
            // An empty network left behind harms nothing; the files below are what matters.
        }

        // The data goes last: if that fails the server stays listed and can be deleted again.
        $this->attempt(fn () => $this->host->removeDatabaseData($server->slug), 'delete the files of');

        // Its dumps go with it: they belong to databases that no longer exist.
        $this->backups->deleteAll($server);
        $server->delete();
    }

    /**
     * @throws DatabaseException
     */
    private function requireInstalled(DatabaseServer $server): void
    {
        if (in_array($server->status, [DatabaseServerStatus::Provisioning, DatabaseServerStatus::Failed], true)) {
            throw new DatabaseException('The server is not installed yet.');
        }
    }

    /**
     * @throws DatabaseException
     */
    private function attempt(callable $action, string $verb): void
    {
        try {
            $action();
        } catch (HostOperationFailed $e) {
            throw new DatabaseException("Could not {$verb} the database server: {$e->getMessage()}", 0, $e);
        }
    }
}
