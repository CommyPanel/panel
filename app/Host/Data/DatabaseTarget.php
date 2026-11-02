<?php

namespace App\Host\Data;

/**
 * A database server (a running container) and how to administer it from inside.
 */
final readonly class DatabaseTarget
{
    public function __construct(
        public string $container,
        public string $engine,
        #[\SensitiveParameter] public string $rootPassword,
    ) {
        ResourceName::assertValid($container);
    }
}
