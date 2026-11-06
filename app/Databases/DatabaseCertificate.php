<?php

namespace App\Databases;

use App\Dns\PanelDomain;
use App\Dns\ServerAddress;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\DatabaseServer;

/**
 * The TLS certificate a database server uses once it can be reached from outside. The agent
 * keeps the keys; the panel keeps only the public certificate of the authority, which it gives
 * to people so their client can check it is talking to this server.
 */
class DatabaseCertificate
{
    public function __construct(
        private readonly HostDriver $host,
        private readonly ServerAddress $address,
        private readonly PanelDomain $panelDomain,
    ) {}

    /**
     * The names and addresses clients connect to: this server's public IPs and the panel's domain.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return array_values(array_filter([$this->address->ipv4(), $this->address->ipv6(), $this->panelDomain->name()]));
    }

    /**
     * Make sure the server has a certificate for its addresses. Returns true when the server has
     * to be started again to use it (a new or changed certificate, or TLS not switched on yet).
     *
     * @throws DatabaseException
     */
    public function ensure(DatabaseServer $server): bool
    {
        try {
            $tls = $this->host->ensureDatabaseTls($server->slug, $server->engine->value, $this->hosts());
        } catch (HostOperationFailed $e) {
            throw new DatabaseException("Could not make the server's certificate: {$e->getMessage()}", 0, $e);
        }

        $needsRestart = ! (bool) $server->tls_enabled || $tls->changed;
        $server->forceFill(['tls_enabled' => true, 'tls_ca' => $tls->caPem])->save();

        return $needsRestart;
    }
}
