<?php

namespace App\Databases;

use App\Audit\AuditLogger;
use App\Enums\DatabaseServerStatus;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\Exceptions\RemotePortInUse;
use App\Host\HostDriver;
use App\Jobs\OpenRemoteAccessJob;
use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Opening a database server to the internet. This is the only code that does it.
 *
 * What is decided here: a random public port, only the given real IP addresses may connect, only
 * with TLS (the server is restarted once to switch it on), and the access closes by itself at the
 * chosen time whether or not anyone is connected, or stays open on purpose ("never"). The agent
 * owns the listener, enforces the address list and the end time itself, and closes everything if it
 * restarts; the scheduler asks it to open again whatever is still wanted.
 */
class RemoteAccess
{
    /** The durations offered, in minutes; null is "never". */
    public const DURATIONS = [15, 30, 60, 240, 1440, 10080];

    public function __construct(
        private readonly HostDriver $host,
        private readonly DatabaseCertificate $certificate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Start opening: the rest happens in the queue, because the server may have to be restarted.
     *
     * @param  string|list<string>  $allow
     *
     * @throws DatabaseException
     */
    public function open(DatabaseServer $server, string|array $allow, ?int $minutes, User $actor): void
    {
        $this->requireRunning($server);
        $this->requireAddress();
        $list = RemoteAllowList::parse($allow);
        $this->requireDuration($minutes);

        if ($server->remoteOpen()) {
            throw new DatabaseException(__('This server is already open. Change it, or close it first.'));
        }

        $server->forceFill([
            'remote_port' => $this->freePort($server),
            'remote_status' => 'opening',
            'remote_error' => null,
            'remote_allow' => $list,
            'remote_forever' => $minutes === null,
            'remote_expires_at' => $minutes === null ? null : now()->addMinutes($minutes),
            'remote_opened_at' => now(),
            'remote_opened_by' => $actor->email,
        ])->save();

        $this->audit->record('database.remote_opened', $actor, $this->details($server));
        OpenRemoteAccessJob::dispatch($server->id);
    }

    /**
     * Change who may connect and for how long, from now. The port stays.
     *
     * @param  string|list<string>  $allow
     *
     * @throws DatabaseException
     */
    public function change(DatabaseServer $server, string|array $allow, ?int $minutes, User $actor): void
    {
        if (! $server->remoteOpen() || $server->remote_status !== 'open') {
            throw new DatabaseException(__('This server is not open to the internet.'));
        }

        $list = RemoteAllowList::parse($allow);
        $this->requireDuration($minutes);

        $before = $this->details($server);
        $server->forceFill([
            'remote_allow' => $list,
            'remote_forever' => $minutes === null,
            'remote_expires_at' => $minutes === null ? null : now()->addMinutes($minutes),
        ]);
        $this->apply($server);
        $server->save();

        $this->audit->record('database.remote_changed', $actor, [...$this->details($server), 'before' => ['allow' => $before['allow'], 'never_expires' => $before['never_expires']]]);
    }

    /**
     * Close it now: the port and every connection on it.
     *
     * @throws DatabaseException
     */
    public function close(DatabaseServer $server, ?User $actor, string $action = 'database.remote_closed'): void
    {
        if (! $server->remoteOpen() && ! $this->agentHas($server)) {
            return;
        }

        try {
            $this->host->closeDatabaseRemote($server->slug);
        } catch (HostOperationFailed $e) {
            // Never claim it is closed when it may not be.
            throw new DatabaseException(__('Could not close the port: :error', ['error' => $e->getMessage()]), 0, $e);
        }

        $details = $this->details($server);

        $server->forceFill([
            'remote_port' => null, 'remote_status' => null, 'remote_error' => null, 'remote_allow' => null,
            'remote_forever' => false, 'remote_expires_at' => null, 'remote_opened_at' => null, 'remote_opened_by' => null,
        ])->save();

        $this->audit->record($action, $actor, $details);
    }

    /**
     * What the queued job does: certificate, restart if needed, then the port. Leaves the access
     * open, or failed with the reason, and never leaves a half-open port behind.
     */
    public function finishOpening(DatabaseServer $server, callable $restart): void
    {
        try {
            if ($this->certificate->ensure($server)) {
                $restart($server);
                $server->refresh();

                if ($server->status !== DatabaseServerStatus::Running) {
                    throw new DatabaseException($server->error ?? 'The server did not start again with TLS.');
                }
            }

            // An expiry that passed while the server restarted needs no port at all.
            if (! $server->remote_forever && $server->remote_expires_at !== null && $server->remote_expires_at->isPast()) {
                $this->close($server, null, 'database.remote_expired');

                return;
            }

            $this->apply($server, retryPort: true);
            $server->forceFill(['remote_status' => 'open', 'remote_error' => null])->save();
        } catch (DatabaseException|HostOperationFailed $e) {
            $this->fail($server, $e->getMessage());
        }
    }

    /**
     * The scheduler's look: close what has run out, open again what the agent lost (it closes
     * everything when it restarts), and close what the panel no longer wants.
     */
    public function reconcile(): void
    {
        try {
            $listeners = collect($this->host->databaseRemoteListeners())->keyBy('server');
        } catch (HostOperationFailed) {
            return;
        }

        foreach (DatabaseServer::query()->whereNotNull('remote_port')->get() as $server) {
            if (! $server->remote_forever && $server->remote_expires_at !== null && $server->remote_expires_at->isPast()) {
                $this->closeQuietly($server, 'database.remote_expired');
                $listeners->forget($server->slug);

                continue;
            }

            if ($server->remote_status === 'open' && ! $listeners->has($server->slug)) {
                try {
                    $this->apply($server);
                } catch (DatabaseException|HostOperationFailed $e) {
                    $this->fail($server, 'The port could not be opened again: '.$e->getMessage());
                }
            }

            $listeners->forget($server->slug);
        }

        // Open on the agent but unknown to the panel (a deleted server, a restored backup): close it.
        foreach ($listeners as $listener) {
            try {
                $this->host->closeDatabaseRemote($listener->server);
            } catch (HostOperationFailed) {
                // Tried again next minute.
            }
        }
    }

    /**
     * The access as a page shows it. `ca` is only for people who may use it.
     *
     * @return array<string, mixed>
     */
    public static function summary(DatabaseServer $server, string $host): array
    {
        return [
            'status' => $server->remote_status,
            'error' => $server->remote_error,
            'port' => $server->remote_port,
            'host' => $host,
            'allow' => $server->remote_allow ?? [],
            'forever' => $server->remote_forever,
            'expires_at' => $server->remote_expires_at?->toIso8601String(),
            'opened_at' => $server->remote_opened_at?->toIso8601String(),
            'opened_by' => $server->remote_opened_by,
            'tls' => $server->tls_enabled,
        ];
    }

    private function apply(DatabaseServer $server, bool $retryPort = false): void
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $this->host->openDatabaseRemote(
                    $server->slug,
                    $server->engine->value,
                    (int) $server->remote_port,
                    (array) $server->remote_allow,
                    $server->remote_forever ? 0 : (int) $server->remote_expires_at?->getTimestamp(),
                );

                return;
            } catch (RemotePortInUse $e) {
                if (! $retryPort || $attempt >= 8) {
                    throw new DatabaseException('The port is taken by something else on this server. Close the access and open it again.', 0, $e);
                }

                $server->forceFill(['remote_port' => $this->freePort($server)])->save();
            }
        }
    }

    private function fail(DatabaseServer $server, string $message): void
    {
        // A failure must not leave a port open.
        try {
            $this->host->closeDatabaseRemote($server->slug);
        } catch (HostOperationFailed) {
            // The scheduler closes it: it is open on the agent but not 'open' here.
        }

        $server->forceFill(['remote_status' => 'failed', 'remote_error' => mb_substr($message, 0, 300)])->save();
        $this->audit->record('database.remote_failed', null, [...$this->details($server), 'reason' => mb_substr($message, 0, 300)]);
    }

    private function closeQuietly(DatabaseServer $server, string $action): void
    {
        try {
            $this->close($server, null, $action);
        } catch (DatabaseException) {
            // Tried again next minute.
        }
    }

    private function agentHas(DatabaseServer $server): bool
    {
        try {
            return collect($this->host->databaseRemoteListeners())->contains(fn ($l) => $l->server === $server->slug);
        } catch (HostOperationFailed) {
            return true;
        }
    }

    /**
     * @throws DatabaseException
     */
    private function requireRunning(DatabaseServer $server): void
    {
        if ($server->status !== DatabaseServerStatus::Running) {
            throw new DatabaseException(__('The server has to be running to open it to the internet.'));
        }
    }

    /**
     * @throws DatabaseException
     */
    private function requireAddress(): void
    {
        if ($this->certificate->hosts() === []) {
            throw new DatabaseException(__('The server\'s public address is not known yet. Set it on the Domains page first: it goes into the certificate.'));
        }
    }

    /**
     * @throws DatabaseException
     */
    private function requireDuration(?int $minutes): void
    {
        if ($minutes !== null && ! in_array($minutes, self::DURATIONS, true)) {
            throw new DatabaseException(__('Choose one of the durations offered.'));
        }
    }

    private function freePort(DatabaseServer $server): int
    {
        [$first, $last] = self::range();
        $taken = DatabaseServer::query()->whereNotNull('remote_port')->whereKeyNot($server->getKey())->pluck('remote_port')->all();

        // Never inside the ports app containers are given, even if someone moved that range here.
        $appFirst = (int) config('commypanel.deploy.port_range_start');
        $appLast = (int) config('commypanel.deploy.port_range_end');

        $free = array_filter(
            range($first, $last),
            fn (int $port) => ! in_array($port, $taken, true) && ($port < $appFirst || $port > $appLast),
        );

        if ($free !== []) {
            return $free[array_rand($free)];
        }

        throw new DatabaseException(__('No free public port is left for a database. Close another one first.'));
    }

    /**
     * The ports a public database port is picked from. They sit apart from the ports app containers
     * use (20000 to 29999, on loopback) and below Linux's own outgoing ports (from 32768), and the
     * agent holds the same range, so a pick can never be refused for being outside it.
     *
     * @return array{int, int}
     */
    public static function range(): array
    {
        return [10000, 19999];
    }

    /**
     * @return array<string, mixed>
     */
    private function details(DatabaseServer $server): array
    {
        return [
            'server' => $server->slug,
            'port' => $server->remote_port,
            'allow' => $server->remote_allow ?? [],
            'never_expires' => $server->remote_forever,
            'expires_at' => $server->remote_expires_at instanceof Carbon ? $server->remote_expires_at->toIso8601String() : null,
        ];
    }
}
