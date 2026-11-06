<?php

namespace App\Databases;

use App\Enums\DatabaseServerStatus;
use App\Host\Data\ContainerSpec;
use App\Host\Data\Volume;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\DatabaseServer;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Starts a database server: downloads the official image, runs it with its data
 * in a folder of its own and no published port, and waits until it answers. It
 * runs as a queued job (a first download takes a while) and leaves the server
 * Running, or Failed with the reason.
 */
class ProvisionDatabaseServer
{
    public function __construct(private readonly HostDriver $host, private readonly DatabaseCertificate $certificate) {}

    public function __invoke(DatabaseServer $server): void
    {
        $server->forceFill(['status' => DatabaseServerStatus::Provisioning, 'error' => null])->save();

        try {
            $this->host->pullImage($server->image());

            // A server that was opened to the internet once keeps its certificate: it starts with TLS.
            if ((bool) $server->tls_enabled) {
                $this->certificate->ensure($server);
            }

            // A retry starts from nothing: whatever is left of an earlier attempt goes, the data stays.
            $this->host->removeContainer($server->containerName());

            $this->host->runContainer(new ContainerSpec(
                name: $server->containerName(),
                image: $server->image(),
                hostPort: 0,
                containerPort: 0,
                env: $server->engine->environment($server->root_password),
                memoryLimitMb: $server->memory_limit_mb ?? (int) config('commypanel.databases.memory_mb'),
                volumes: [new Volume($this->dataPath($server), $server->engine->dataPath())],
                network: $server->networkName(),
                databaseTls: (bool) $server->tls_enabled,
            ));

            $this->waitUntilReady($server);
        } catch (HostOperationFailed|DatabaseException $e) {
            $server->forceFill(['status' => DatabaseServerStatus::Failed, 'error' => $e->getMessage()])->save();

            return;
        } catch (Throwable $e) {
            report($e);
            $server->forceFill(['status' => DatabaseServerStatus::Failed, 'error' => 'Something went wrong while starting the server. See the panel log.'])->save();

            return;
        }

        $server->forceFill(['status' => DatabaseServerStatus::Running, 'error' => null])->save();
    }

    public function dataPath(DatabaseServer $server): string
    {
        return rtrim((string) config('commypanel.data_path'), '/').'/databases/'.$server->slug;
    }

    /**
     * @throws DatabaseException
     */
    private function waitUntilReady(DatabaseServer $server): void
    {
        $deadline = now()->addSeconds((int) config('commypanel.databases.ready_timeout'));

        while ($this->host->databaseReady($server->target()) === false) {
            if (now()->greaterThan($deadline)) {
                throw new DatabaseException('The server did not answer in time. Check its logs on Server > Docker, then try again.');
            }

            Sleep::for(3)->seconds();
        }
    }
}
