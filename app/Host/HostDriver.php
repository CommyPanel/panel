<?php

namespace App\Host;

use App\Host\Data\CertificateInfo;
use App\Host\Data\CertificateRequest;
use App\Host\Data\ContainerInfo;
use App\Host\Data\ContainerSpec;
use App\Host\Data\DockerInfo;
use App\Host\Data\HostInfo;
use App\Host\Data\ImageBuildSpec;
use App\Host\Data\ImageInfo;
use App\Host\Data\NginxSite;
use App\Host\Data\NginxStatus;
use App\Host\Data\NginxTestResult;
use App\Host\Data\SystemStatus;
use App\Host\Data\TaskSpec;
use App\Host\Exceptions\DockerUnavailable;
use App\Host\Exceptions\HostOperationFailed;

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
     * @throws HostOperationFailed when the agent cannot be reached; an
     *                             unreachable Docker daemon is reported in the result instead
     */
    public function dockerInfo(): DockerInfo;

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
     * @throws HostOperationFailed
     */
    public function removeNginxSite(string $name): void;

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
     */
    public function listContainers(): array;

    /**
     * @throws HostOperationFailed
     */
    public function containerLogs(string $name, int $tail = 200): string;

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
