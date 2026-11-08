<?php

namespace App\FastApps;

use App\Enums\DatabaseEngine;
use App\Enums\Stack;
use App\Models\App;

/**
 * A quick-install app: software people want running without writing code (a CMS). CommyPanel
 * keeps the recipe: the build context (a Dockerfile on top of the official image and a few small
 * files), the volumes that keep its data, the database it needs and how its connection variables
 * are named. It goes through the normal deploy pipeline like any other app.
 */
interface FastApp
{
    /** Short id used in addresses and the database: `wordpress`. */
    public function id(): string;

    public function name(): string;

    public function description(): string;

    public function stack(): Stack;

    /**
     * The database engines it works with.
     *
     * @return list<DatabaseEngine>
     */
    public function engines(): array;

    /**
     * What the person fills in. The first value of each is taken from the app's name when empty.
     *
     * @return list<Field>
     */
    public function fields(): array;

    public function containerPort(): int;

    public function healthPath(): string;

    /** The path inside the container to open after installing, for example `/wp-admin/`. */
    public function adminPath(): ?string;

    /**
     * Container paths kept in persistent volumes.
     *
     * @return list<string>
     */
    public function volumePaths(): array;

    /** The prefix of the connection variables of the linked database (`WORDPRESS_DB` gives `WORDPRESS_DB_HOST`...). */
    public function databasePrefix(): string;

    public function memoryLimitMb(): ?int;

    /** Extra nginx directives the app needs (for example a bigger upload limit), or null. */
    public function nginxCustom(): ?string;

    /**
     * The environment variables to store for a new app: the installation settings, and secrets the
     * software needs to stay the same between deployments.
     *
     * @param  array<string, string>  $settings  the validated values of `fields()`
     * @return array<string, string>
     */
    public function environment(array $settings): array;

    /**
     * The variables that only matter for the first start and are removed once it went live.
     *
     * @return list<string>
     */
    public function oneTimeVariables(): array;

    /**
     * The build context: file name => contents. `Dockerfile` must be one of them.
     *
     * @return array<string, string>
     */
    public function files(App $app): array;

    /** A label for the build log and the release list. */
    public function release(): string;
}
