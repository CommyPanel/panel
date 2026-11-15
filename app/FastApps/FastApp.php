<?php

namespace App\FastApps;

use App\Models\App;

/**
 * A quick-install app: software people want running without writing code (a search engine, a
 * dashboard). CommyPanel keeps the recipe: the build context (a Dockerfile on top of a pinned
 * official image and a few small files), the volumes that keep its data and the variables it needs.
 * It goes through the normal deploy pipeline like any other app.
 */
interface FastApp
{
    /** Short id used in addresses and the database: `meilisearch`. */
    public function id(): string;

    public function name(): string;

    public function description(): string;

    /**
     * What the person chooses. Most recipes need nothing.
     *
     * @return list<Field>
     */
    public function fields(): array;

    /** The id of the recipe whose app this one connects to (Kibana needs an Elasticsearch app), or null. */
    public function requires(): ?string;

    public function containerPort(): int;

    public function healthPath(): string;

    /** Seconds the first start may take before it is called failed, or null for the panel's default. */
    public function healthTimeout(): ?int;

    public function memoryLimitMb(): ?int;

    /**
     * Container paths kept in persistent volumes.
     *
     * @return list<string>
     */
    public function volumePaths(): array;

    /**
     * Things to know before installing (memory, host settings), shown on the create page.
     *
     * @return list<string>
     */
    public function warnings(): array;

    /**
     * The environment variables to store for a new app, secrets included (generated here, so they
     * stay the same between deployments).
     *
     * @param  array<string, string>  $settings  the validated values of `fields()`
     * @return array<string, string>
     */
    public function environment(array $settings): array;

    /**
     * Variables only the first start needs; they are removed once it went live.
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

    /** A label for the build log and the release list (the image and its tag). */
    public function release(): string;

    /**
     * What a person needs to sign in or connect, read from the app's current variables.
     *
     * @return list<array{label: string, value: string, secret: bool}>
     */
    public function credentials(App $app): array;
}
