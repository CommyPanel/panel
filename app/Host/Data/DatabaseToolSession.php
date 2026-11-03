<?php

namespace App\Host\Data;

/**
 * One opening of a database in its tool (phpMyAdmin, Adminer), as the agent
 * reports it. It never holds a secret.
 */
final readonly class DatabaseToolSession
{
    public const READY = 'ready';

    public const FAILED = 'failed';

    public function __construct(
        public string $id,
        public string $stage,
        public string $tool,
        public string $server,
        public string $database,
        public ?string $error = null,
    ) {}

    public function isReady(): bool
    {
        return $this->stage === self::READY;
    }

    public function hasFailed(): bool
    {
        return $this->stage === self::FAILED;
    }
}
