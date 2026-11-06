<?php

namespace App\Host\Data;

/**
 * The certificate authority of a database server. Only its public certificate is ever
 * known to the panel; the keys stay with the agent.
 */
final readonly class DatabaseTls
{
    public function __construct(public string $caPem, public bool $changed) {}
}
