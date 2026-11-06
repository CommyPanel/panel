<?php

namespace App\Host\Data;

/** A public port the agent has open for a database server. */
final readonly class RemoteListener
{
    /**
     * @param  list<string>  $allow
     */
    public function __construct(
        public string $server,
        public int $port,
        public array $allow,
        public int $expiresAt,
        public int $connections,
        public int $total,
    ) {}
}
