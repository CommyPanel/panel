<?php

namespace App\Host;

use App\Host\Data\CertificateInfo;
use App\Host\Data\CertificateRequest;
use App\Host\Data\ContainerInfo;
use App\Host\Data\ContainerSpec;
use App\Host\Data\DatabaseTarget;
use App\Host\Data\DatabaseTls;
use App\Host\Data\DatabaseToolSession;
use App\Host\Data\DockerDiagnosis;
use App\Host\Data\DockerInfo;
use App\Host\Data\HostInfo;
use App\Host\Data\ImageBuildSpec;
use App\Host\Data\ImageInfo;
use App\Host\Data\NetworkInfo;
use App\Host\Data\NginxSite;
use App\Host\Data\NginxStatus;
use App\Host\Data\NginxTestResult;
use App\Host\Data\PublicAddresses;
use App\Host\Data\RemoteListener;
use App\Host\Data\ResourceUsage;
use App\Host\Data\SystemStatus;
use App\Host\Data\TaskSpec;
use App\Host\Data\TrafficStat;
use App\Host\Data\VolumeFilePreview;
use App\Host\Data\VolumeListing;
use App\Host\Data\VolumeUsage;
use App\Host\Exceptions\DockerUnavailable;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\Exceptions\RemotePortInUse;

/**
 * Every privileged operation on the server goes through this interface.
 * The real implementation talks to commypanel-agent; tests and macOS
 * development use the fake driver. Never run host commands outside of it.
 */
interface HostDriver
{
    public function systemStatus(): SystemStatus;

    /**
     * Host facts for the Server page: system, memory, disk, service states.
     *
     * @throws HostOperationFailed
     */
    public function systemInfo(): HostInfo;

    /**
     * The public IP addresses on the server's own network interfaces (nothing is
     * looked up over the network).
     *
     * @throws HostOperationFailed
     */
    public function publicAddresses(): PublicAddresses;

    /**
     * How busy the host and its running containers are right now. CPU and
     * network are rates, so the first readings after the agent starts (or the
     * first time a container is looked at) can be missing. Reading it keeps
     * the agent sampling containers for a minute.
     *
     * @param  bool  $history  also return the last few minutes
     *
     * @throws HostOperationFailed
     */
    public function resourceUsage(bool $history = false): ResourceUsage;

    /**
     * @throws HostOperationFailed when the agent cannot be reached; an
     *                             unreachable Docker daemon is reported in the result instead
     */
    public function dockerInfo(): DockerInfo;

    /**
     * Whether Docker answers and, from systemd, why not: unit states and the
     * daemon's latest log lines.
     *
     * @throws HostOperationFailed when the agent cannot be reached
     */
    public function dockerDiagnosis(): DockerDiagnosis;

    /**
     * Restart the Docker daemon. Every container stops and comes back through
     * its restart policy, so apps are briefly unavailable.
     *
     * @throws HostOperationFailed
     */
    public function restartDocker(): void;

    /**
     * The images CommyPanel built (the commypanel/ namespace), newest first.
     *
     * @return list<ImageInfo>
     *
     * @throws HostOperationFailed
     */
    public function listImages(): array;

    /**
     * @throws HostOperationFailed
     */
    public function nginxStatus(): NginxStatus;

    /**
     * Check the nginx configuration without changing anything.
     *
     * @throws HostOperationFailed
     */
    public function testNginx(): NginxTestResult;

    /**
     * Test the configuration and reload nginx gracefully.
     *
     * @throws HostOperationFailed when the configuration is invalid or the reload fails
     */
    public function reloadNginx(): void;

    /**
     * Validate, write and gracefully reload an nginx site.
     *
     * @throws HostOperationFailed
     */
    public function applyNginxSite(NginxSite $site): void;

    /**
     * The config a site would get, without writing or reloading anything.
     *
     * @throws HostOperationFailed
     */
    public function renderNginxSite(NginxSite $site): string;

    /**
     * Check custom nginx directives on their own, without a site. Does nothing
     * when they are acceptable.
     *
     * @throws HostOperationFailed with the reason and line numbers when they are refused
     */
    public function checkNginxCustom(string $custom): void;

    /**
     * @throws HostOperationFailed
     */
    public function removeNginxSite(string $name): void;

    /**
     * How a site's containers did over a time range (unix seconds), per container port, from
     * the requests nginx proxied. A port that served nothing in the range is missing. With a
     * step (a multiple of 10 seconds) each stat also carries a series.
     *
     * @return array<int, TrafficStat>
     *
     * @throws HostOperationFailed when the agent has no statistics
     */
    public function trafficStats(string $site, int $from, int $to, int $step = 0): array;

    /**
     * @param  callable(string): void|null  $onOutput  receives build log lines
     *
     * @throws HostOperationFailed
     */
    public function buildImage(ImageBuildSpec $spec, ?callable $onOutput = null): void;

    /**
     * @throws DockerUnavailable
     * @throws HostOperationFailed
     */
    public function runContainer(ContainerSpec $spec): void;

    /**
     * @throws HostOperationFailed
     */
    public function stopContainer(string $name): void;

    /**
     * Start a stopped managed container. Starting a running one succeeds.
     *
     * @throws HostOperationFailed
     */
    public function startContainer(string $name): void;

    /**
     * Stop a managed container with a grace period and start it again.
     *
     * @throws HostOperationFailed
     */
    public function restartContainer(string $name): void;

    /**
     * @throws HostOperationFailed
     */
    public function removeContainer(string $name): void;

    /**
     * Run a one-off command to completion in a new container and return its
     * exit code. The container is removed afterwards. A non-zero exit code is
     * a result, not an exception.
     *
     * @param  callable(string): void|null  $onOutput  receives output lines
     *
     * @throws DockerUnavailable
     * @throws HostOperationFailed when the command cannot be run or times out
     */
    public function runTask(TaskSpec $spec, ?callable $onOutput = null): int;

    /**
     * Managed containers only; containers the agent did not create are never listed.
     *
     * @return list<ContainerInfo>
     *
     * @throws DockerUnavailable
     * @throws HostOperationFailed when the agent cannot be reached
     */
    public function listContainers(): array;

    /**
     * Delete an app's own network once nothing is attached to it. A network that
     * is already gone is not an error.
     *
     * @throws HostOperationFailed
     */
    public function removeNetwork(string $name): void;

    /**
     * Download an official database image (mariadb, mysql or postgres with a
     * plain version tag). An image that is already there is not downloaded again.
     *
     * @throws HostOperationFailed
     */
    public function pullImage(string $image): void;

    /**
     * Whether a database server answers a query. One that is starting, or stopped,
     * is not ready, which is not an error.
     *
     * @throws HostOperationFailed
     */
    public function databaseReady(DatabaseTarget $server): bool;

    /**
     * Create a database and a user that owns it and nothing else. Doing it again
     * with the same names only sets the password.
     *
     * @throws HostOperationFailed
     */
    public function createDatabase(DatabaseTarget $server, string $database, string $user, #[\SensitiveParameter] string $password): void;

    /**
     * Delete a database and its user. What does not exist is not an error.
     *
     * @throws HostOperationFailed
     */
    public function dropDatabase(DatabaseTarget $server, string $database, string $user): void;

    /**
     * @throws HostOperationFailed
     */
    public function setDatabasePassword(DatabaseTarget $server, string $user, #[\SensitiveParameter] string $password): void;

    /**
     * Write a dump of one database, compressed, to a file. The file is only
     * left in place when the dump succeeded.
     *
     * @throws HostOperationFailed
     */
    public function dumpDatabase(DatabaseTarget $server, string $database, string $toPath): void;

    /**
     * Load a dump the agent made earlier into an existing, empty database. `$file`
     * is its name in the server's backup folder; the agent refuses any file it
     * did not make itself. In `replace` mode the database and its user must already
     * exist: the agent drops and recreates the database and loads the dump into it.
     *
     * @param  'new'|'replace'  $mode
     *
     * @throws HostOperationFailed
     */
    public function restoreDatabase(DatabaseTarget $server, string $database, string $user, string $serverSlug, string $file, string $mode = 'new'): void;

    /**
     * Write a volume as a gzipped tar to `$toPath`. The agent skips links and
     * special files and keeps owners as numbers.
     *
     * @throws HostOperationFailed
     */
    public function backupVolume(string $app, string $name, string $toPath): void;

    /**
     * Unpack an archive the agent made (`$archive` is its file name in the app's
     * backup folder) over a volume's folder. The agent refuses while a container
     * has the volume mounted and any file it did not make itself.
     *
     * @return array{files: int, bytes: int}
     *
     * @throws HostOperationFailed
     */
    public function restoreVolume(string $app, string $name, string $archive): array;

    /**
     * Begin opening a database in its tool (phpMyAdmin for MySQL and MariaDB, pgAdmin for
     * PostgreSQL): the agent starts a short-lived container next to the database server,
     * logged in as that database's own user, and removes it after 15 minutes without use.
     * Returns at once; follow it with databaseToolStatus. `$publicBase` is the web address of
     * the tools port as the browser reaches it, such as https://panel.example.com:2053.
     *
     * @throws HostOperationFailed
     */
    public function startDatabaseTool(string $engine, string $server, string $database, string $user, #[\SensitiveParameter] string $password, string $publicBase): DatabaseToolSession;

    /**
     * How far a tool session is, or null when there is none (it ended, or the agent restarted).
     *
     * @throws HostOperationFailed
     */
    public function databaseToolStatus(string $session): ?DatabaseToolSession;

    /**
     * The single-use secret that lets a browser into a ready tool session.
     *
     * @throws HostOperationFailed
     */
    public function databaseToolTicket(string $session): string;

    /**
     * Close a tool session and remove its container.
     *
     * @throws HostOperationFailed
     */
    public function stopDatabaseTool(string $session): void;

    /**
     * Delete the data folder of a database server, which must have no container.
     *
     * @throws HostOperationFailed
     */
    public function removeDatabaseData(string $server): void;

    /**
     * Make sure a database server has a certificate that covers `$hosts` (addresses and names its
     * clients use), made by its own certificate authority. `changed` says the certificate was
     * written anew, so the server has to be restarted to use it.
     *
     * @param  list<string>  $hosts
     */
    public function ensureDatabaseTls(string $server, string $engine, array $hosts): DatabaseTls;

    /**
     * Open (or change) the public port of a database server: only the given addresses or ranges
     * may connect, only with TLS, and the agent closes it itself at `$expiresAt` (a unix time,
     * 0 for never). Doing it again with the same port changes the list and the time.
     *
     * @param  list<string>  $allow
     *
     * @throws RemotePortInUse when something else holds the port
     */
    public function openDatabaseRemote(string $server, string $engine, int $port, array $allow, int $expiresAt): void;

    /** Close the public port of a database server and every connection on it. Closed already is fine. */
    public function closeDatabaseRemote(string $server): void;

    /**
     * What the agent has open now.
     *
     * @return list<RemoteListener>
     */
    public function databaseRemoteListeners(): array;

    /**
     * Attach a running container to a link network, with the names it answers to there.
     *
     * @param  list<string>  $aliases
     *
     * @throws HostOperationFailed
     */
    public function connectNetwork(string $container, string $network, array $aliases = []): void;

    /**
     * @throws HostOperationFailed
     */
    public function disconnectNetwork(string $container, string $network): void;

    /**
     * Remove the link networks nothing is attached to; returns their names.
     *
     * @return list<string>
     *
     * @throws HostOperationFailed
     */
    public function pruneLinkNetworks(): array;

    /**
     * A single-use ticket that lets one browser open a terminal in one managed
     * container, from one origin (such as https://panel.example.com). The
     * ticket is only valid for a few seconds.
     *
     * @throws HostOperationFailed
     */
    public function createTerminalTicket(string $container, string $origin): string;

    /**
     * The Docker networks CommyPanel created, with what is attached to each.
     *
     * @return list<NetworkInfo>
     *
     * @throws DockerUnavailable
     * @throws HostOperationFailed when the agent cannot be reached
     */
    public function listNetworks(): array;

    /**
     * @throws HostOperationFailed
     */
    public function containerLogs(string $name, int $tail = 200): string;

    /**
     * Delete the data of one of an app's volumes, or of all of them when no name
     * is given. Nothing may still mount it.
     *
     * @throws HostOperationFailed
     */
    public function removeVolumes(string $app, ?string $name = null): void;

    /**
     * The volume folders that exist on the host for an app, with their size.
     *
     * @return list<VolumeUsage>
     *
     * @throws HostOperationFailed
     */
    public function listVolumes(string $app): array;

    /**
     * The items in a folder of a volume. `$path` is relative to the volume and
     * empty for its top. Read only; links are listed but never followed.
     *
     * @throws HostOperationFailed
     */
    public function browseVolume(string $app, string $name, string $path = ''): VolumeListing;

    /**
     * The start of a plain file as text.
     *
     * @throws HostOperationFailed
     */
    public function previewVolumeFile(string $app, string $name, string $path): VolumeFilePreview;

    /**
     * The whole content of a plain file, up to 25 MB.
     *
     * @throws HostOperationFailed
     */
    public function readVolumeFile(string $app, string $name, string $path): string;

    /**
     * Remove dangling images and return the bytes reclaimed.
     *
     * @throws HostOperationFailed
     */
    public function pruneDanglingImages(): int;

    /**
     * Remove one of CommyPanel's own images (tags under commypanel/). A
     * missing image is not an error.
     *
     * @throws HostOperationFailed
     */
    public function removeImage(string $tag): void;

    /**
     * Obtain a certificate from Let's Encrypt (HTTP-01) and store it under the
     * request's name. Can take a minute or more.
     *
     * @throws HostOperationFailed with the CA's explanation when validation fails
     */
    public function issueCertificate(CertificateRequest $request): CertificateInfo;

    /**
     * @throws HostOperationFailed
     */
    public function certificateInfo(string $name): ?CertificateInfo;

    /**
     * @throws HostOperationFailed
     */
    public function removeCertificate(string $name): void;
}
