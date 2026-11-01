<?php

namespace App\Host\Data;

/**
 * Why Docker is or is not answering, from the daemon and from systemd.
 */
final readonly class DockerDiagnosis
{
    public function __construct(
        public bool $available,
        public ?string $error,
        /** State of docker.service: active, failed, inactive, ... or unknown. */
        public string $service,
        /** State of docker.socket. */
        public string $socket,
        /** The daemon's latest journal lines. */
        public string $journal,
    ) {}
}
