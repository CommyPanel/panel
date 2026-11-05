<?php

namespace App\Databases;

use App\Dns\PanelDomain;
use App\Dns\ServerAddress;
use App\Host\Data\DatabaseToolSession;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;
use Illuminate\Http\Request;

/**
 * Opening a database in phpMyAdmin (MySQL, MariaDB) or pgAdmin (PostgreSQL) without
 * installing anything: a short-lived container of the tool, logged in as that one
 * database's own user, which the agent removes after 15 minutes without use.
 *
 * The tool is served on a port of its own (`commypanel.tools_port`), which a browser
 * treats as another website than the panel, so the tool can never act as the panel.
 */
class DatabaseTools
{
    /** How long the agent keeps a tool open without use. Shown to people; the agent enforces it. */
    public const IDLE_MINUTES = 15;

    public function __construct(
        private readonly HostDriver $host,
        private readonly PanelDomain $panel,
        private readonly ServerAddress $address,
    ) {}

    public static function toolName(ManagedDatabase|DatabaseServer $of): string
    {
        $server = $of instanceof ManagedDatabase ? $of->server : $of;

        return $server->engine->value === 'postgres' ? 'pgAdmin' : 'phpMyAdmin';
    }

    /**
     * Begin opening a database in its tool.
     *
     * @throws DatabaseException
     */
    public function start(ManagedDatabase $database, Request $request): DatabaseToolSession
    {
        $server = $database->server;

        if (! $server->isRunning()) {
            throw new DatabaseException('The database server is not running. Start it first.');
        }

        if ($database->restore_status !== null) {
            throw new DatabaseException('A backup is being loaded into this database, or loading it failed. Open it when that is settled.');
        }

        try {
            return $this->host->startDatabaseTool(
                $server->engine->value,
                $server->slug,
                $database->name,
                $database->username,
                $database->password,
                $this->publicBase($request),
            );
        } catch (HostOperationFailed $e) {
            throw new DatabaseException('Could not start '.self::toolName($database).': '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * The session, if it belongs to this database. A session of another database is no
     * session here: the permission is for databases in general, but an address should
     * only ever work for the database it was made for.
     *
     * @throws DatabaseException
     */
    public function status(ManagedDatabase $database, string $session): ?DatabaseToolSession
    {
        try {
            $found = $this->host->databaseToolStatus($session);
        } catch (HostOperationFailed $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }

        if ($found === null || $found->server !== $database->server->slug || $found->database !== $database->name) {
            return null;
        }

        return $found;
    }

    /**
     * The address that logs the browser in: the tools port, with a single-use ticket.
     *
     * @throws DatabaseException
     */
    public function openUrl(ManagedDatabase $database, string $session, Request $request): string
    {
        if ($this->status($database, $session)?->isReady() !== true) {
            throw new DatabaseException('The tool is not ready, or has closed. Open the database again.');
        }

        try {
            $ticket = $this->host->databaseToolTicket($session);
        } catch (HostOperationFailed $e) {
            throw new DatabaseException('The tool could not be opened: '.$e->getMessage(), 0, $e);
        }

        return $this->publicBase($request).'/open?t='.rawurlencode($ticket);
    }

    /**
     * @throws DatabaseException
     */
    public function stop(ManagedDatabase $database, string $session): void
    {
        if ($this->status($database, $session) === null) {
            return;
        }

        try {
            $this->host->stopDatabaseTool($session);
        } catch (HostOperationFailed $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Where the browser reaches the tools: the address it is using for the panel, on the tools
     * port. Only an address the panel really answers on is used (its domain, the server's own
     * addresses, localhost); a Host header that is anything else (a forged one) is replaced by the
     * panel's domain or the server's address, so a ticket is never sent to a name somebody else chose.
     */
    public function publicBase(Request $request): string
    {
        $host = strtolower($request->getHost());
        $known = array_values(array_filter([$this->panel->name(), $this->address->ipv4(), $this->address->ipv6()]));

        if ($known !== [] && ! in_array(trim($host, '[]'), [...$known, 'localhost', '127.0.0.1', '::1'], true)) {
            $host = $known[0];
        }

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']'; // an IPv6 address
        }

        return 'https://'.$host.':'.(int) config('commypanel.tools_port');
    }
}
