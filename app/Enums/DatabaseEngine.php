<?php

namespace App\Enums;

/**
 * The database servers CommyPanel can run, each from its official image.
 */
enum DatabaseEngine: string
{
    case Mariadb = 'mariadb';
    case Mysql = 'mysql';
    case Postgres = 'postgres';

    public function label(): string
    {
        return match ($this) {
            self::Mariadb => 'MariaDB',
            self::Mysql => 'MySQL',
            self::Postgres => 'PostgreSQL',
        };
    }

    public function port(): int
    {
        return $this === self::Postgres ? 5432 : 3306;
    }

    /** The scheme of a connection URL. */
    public function scheme(): string
    {
        return $this === self::Postgres ? 'postgresql' : 'mysql';
    }

    /** Where the image keeps its data, which is what the data folder is mounted at. */
    public function dataPath(): string
    {
        return $this === self::Postgres ? '/var/lib/postgresql/data' : '/var/lib/mysql';
    }

    /**
     * The environment that makes the image set its administrator password on first start.
     *
     * @return array<string, string>
     */
    public function environment(#[\SensitiveParameter] string $rootPassword): array
    {
        return match ($this) {
            self::Mariadb => ['MARIADB_ROOT_PASSWORD' => $rootPassword],
            self::Mysql => ['MYSQL_ROOT_PASSWORD' => $rootPassword],
            self::Postgres => ['POSTGRES_PASSWORD' => $rootPassword],
        };
    }

    /**
     * The versions offered, newest first.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        /** @var list<string> */
        return (array) config("commypanel.databases.versions.{$this->value}", []);
    }

    public function image(string $version): string
    {
        return "{$this->value}:{$version}";
    }
}
