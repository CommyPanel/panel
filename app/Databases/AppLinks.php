<?php

namespace App\Databases;

use App\Dns\PanelDomain;
use App\Host\Data\NetworkAttachment;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppDatabaseLink;
use App\Models\AppLink;
use App\Models\ManagedDatabase;

/**
 * Links let one app reach a database, or another app. Apps are isolated by
 * default: every link gets a network of its own, which only the two containers
 * on either end join, so nothing else can use it.
 *
 * The consumer's containers join the link network when they start (so a new
 * link applies from the next deployment, like an environment variable), and the
 * database server's or the other app's running container joins at once.
 */
class AppLinks
{
    public function __construct(private readonly HostDriver $host) {}

    /**
     * @throws DatabaseException
     */
    public function linkDatabase(App $app, ManagedDatabase $database, string $prefix): AppDatabaseLink
    {
        $prefix = DatabaseNames::envPrefix($prefix);
        $server = $database->server;

        if (! $server->isRunning()) {
            throw new DatabaseException('The database server is not running. Start it first.');
        }

        $this->assertPrefixFree($app, $prefix);

        if ($app->databaseLinks()->where('managed_database_id', $database->id)->exists()) {
            throw new DatabaseException('This app is already linked to that database.');
        }

        /** @var AppDatabaseLink $link */
        $link = $app->databaseLinks()->create(['managed_database_id' => $database->id, 'env_prefix' => $prefix]);

        try {
            $this->host->connectNetwork($server->containerName(), $link->networkName(), [$server->slug]);
        } catch (HostOperationFailed $e) {
            $link->delete();

            throw new DatabaseException("Could not connect the database server: {$e->getMessage()}", 0, $e);
        }

        return $link;
    }

    public function unlinkDatabase(AppDatabaseLink $link): void
    {
        $server = $link->database->server;

        $this->leave($server->containerName(), $link->networkName());
        $link->delete();
        $this->prune();
    }

    /**
     * @throws DatabaseException
     */
    public function linkApp(App $app, App $target, string $prefix): AppLink
    {
        $prefix = DatabaseNames::envPrefix($prefix);

        if ($app->is($target)) {
            throw new DatabaseException('An app cannot be linked to itself.');
        }

        if ($target->slug === PanelDomain::SLUG) {
            throw new DatabaseException('That app cannot be linked.');
        }

        if ($app->links()->where('target_app_id', $target->id)->exists()) {
            throw new DatabaseException("{$app->name} is already linked to {$target->name}.");
        }

        $this->assertPrefixFree($app, $prefix);

        /** @var AppLink $link */
        $link = $app->links()->create(['target_app_id' => $target->id, 'env_prefix' => $prefix]);

        // The other app's running container answers to its own name on the new network at once.
        $live = $target->activeDeployment()->first();

        if ($live !== null && $live->container_name !== null && $target->stopped_at === null) {
            try {
                $this->host->connectNetwork($live->container_name, $link->networkName(), [$target->slug]);
            } catch (HostOperationFailed $e) {
                $link->delete();

                throw new DatabaseException("Could not connect {$target->name}: {$e->getMessage()}", 0, $e);
            }
        }

        return $link;
    }

    public function unlinkApp(AppLink $link): void
    {
        $live = $link->target->activeDeployment()->first();

        if ($live !== null && $live->container_name !== null) {
            $this->leave($live->container_name, $link->networkName());
        }

        $link->delete();
        $this->prune();
    }

    /**
     * Take a deleted app out of every link it is part of, on the host as well.
     */
    public function removeAllFor(App $app): void
    {
        $databaseLinks = $app->databaseLinks()->with('database.server')->get();
        $incoming = $app->linkedFrom()->get();

        if ($databaseLinks->isEmpty() && $incoming->isEmpty() && ! $app->links()->exists()) {
            return;
        }

        foreach ($databaseLinks as $link) {
            $this->leave($link->database->server->containerName(), $link->networkName());
        }

        $live = $app->activeDeployment()->first();

        foreach ($incoming as $link) {
            if ($live !== null && $live->container_name !== null) {
                $this->leave($live->container_name, $link->networkName());
            }
        }

        $app->databaseLinks()->delete();
        $app->links()->delete();
        $app->linkedFrom()->delete();
        $this->prune();
    }

    /**
     * The connection details the app gets as environment variables.
     *
     * @return array<string, string>
     */
    public function environment(App $app): array
    {
        $env = [];

        foreach ($app->databaseLinks()->with('database.server')->get() as $link) {
            $connection = DatabaseConnection::for($link->database);
            $p = $link->env_prefix;

            $env["{$p}_URL"] = $connection->url;
            $env["{$p}_HOST"] = $connection->host;
            $env["{$p}_PORT"] = (string) $connection->port;
            $env["{$p}_NAME"] = $connection->name;
            $env["{$p}_USER"] = $connection->user;
            $env["{$p}_PASSWORD"] = $connection->password;
        }

        foreach ($app->links()->with('target')->get() as $link) {
            $p = $link->env_prefix;
            $port = (string) $link->target->container_port;

            $env["{$p}_URL"] = "http://{$link->target->slug}:{$port}";
            $env["{$p}_HOST"] = $link->target->slug;
            $env["{$p}_PORT"] = $port;
        }

        return $env;
    }

    /**
     * The link networks this app's containers join, and the names they have there.
     *
     * @return list<NetworkAttachment>
     */
    public function attachments(App $app, bool $providing = true): array
    {
        $out = [];

        foreach ($app->databaseLinks()->get() as $link) {
            $out[] = new NetworkAttachment($link->networkName());
        }

        foreach ($app->links()->get() as $link) {
            $out[] = new NetworkAttachment($link->networkName());
        }

        if ($providing) {
            $out = [...$out, ...$this->providedNetworks($app)];
        }

        return $out;
    }

    /**
     * The networks other apps reach this one on, by this app's name. A new release
     * joins these only once it is healthy and has traffic (see DeployApp), so that the
     * old and the new release never answer to the name at the same time for long.
     *
     * @return list<NetworkAttachment>
     */
    public function providedNetworks(App $app): array
    {
        $out = [];

        foreach ($app->linkedFrom()->get() as $link) {
            $out[] = new NetworkAttachment($link->networkName(), [$app->slug]);
        }

        return $out;
    }

    /**
     * Remove link networks nothing uses any more. A failure only leaves an empty network.
     */
    public function prune(): void
    {
        try {
            $this->host->pruneLinkNetworks();
        } catch (HostOperationFailed) {
            // Harmless: the next cleanup removes it.
        }
    }

    private function leave(string $container, string $network): void
    {
        try {
            $this->host->disconnectNetwork($container, $network);
        } catch (HostOperationFailed) {
            // The container is gone or already detached; either way it is not on the network.
        }
    }

    /**
     * @throws DatabaseException
     */
    private function assertPrefixFree(App $app, string $prefix): void
    {
        $taken = $app->databaseLinks()->where('env_prefix', $prefix)->exists() || $app->links()->where('env_prefix', $prefix)->exists();

        if ($taken) {
            throw new DatabaseException("{$prefix} is already used by another link of this app. Choose another name.");
        }
    }
}
