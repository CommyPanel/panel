<?php

namespace App\Databases;

use App\Models\ManagedDatabase;

/**
 * What an app needs to reach one database: where it is, and who to be. It is
 * reached by the server's own name, which only the linked app can resolve.
 */
final readonly class DatabaseConnection
{
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        #[\SensitiveParameter] public string $password,
        public string $url,
    ) {}

    public static function for(ManagedDatabase $database): self
    {
        $server = $database->server;
        $host = $server->slug;
        $port = $server->engine->port();

        return new self(
            host: $host,
            port: $port,
            name: $database->name,
            user: $database->username,
            password: $database->password,
            url: sprintf('%s://%s:%s@%s:%d/%s', $server->engine->scheme(), rawurlencode($database->username), rawurlencode($database->password), $host, $port, $database->name),
        );
    }

    /**
     * The same connection with the password hidden, for showing.
     */
    public function masked(): string
    {
        return str_replace(rawurlencode($this->password), '********', $this->url);
    }
}
