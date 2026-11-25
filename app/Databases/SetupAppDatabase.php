<?php

namespace App\Databases;

use App\Audit\AuditLogger;
use App\Enums\DatabaseEngine;
use App\Enums\DatabaseServerStatus;
use App\Models\App;
use App\Models\AppDatabaseLink;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The database step of the Laravel wizard: one place that carries out the person's choice and links the
 * database to the app with Laravel's own variable names (DB_CONNECTION, DB_HOST, DB_DATABASE ...).
 *
 * Four choices:
 *  - EXISTING: an existing database on an existing server is linked.
 *  - NEW: a new database is made on an existing server (with its own user, as always) and linked.
 *  - DEDICATED: a new database server is made for this app alone. Its user may create and drop databases
 *    on that server (multi-database and tenancy packages). The server starts in the background, so the
 *    first database is made by resume() once it runs; the wizard polls status() until then.
 *  - NONE: nothing is linked (SQLite, or no database).
 *
 * The choice and how far it got are kept in apps.stack_options['database_step']; no password is ever kept there.
 */
class SetupAppDatabase
{
    public const EXISTING = 'existing';

    public const NEW = 'new';

    public const DEDICATED = 'dedicated';

    public const NONE = 'none';

    public const MODES = [self::EXISTING, self::NEW, self::DEDICATED, self::NONE];

    /** The step has not finished: a dedicated server is still starting. */
    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public function __construct(
        private readonly DatabaseServers $servers,
        private readonly ManageDatabases $databases,
        private readonly AppLinks $links,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Carry out a choice. `$choice` is already validated for shape (see SetupDatabaseController); what is
     * checked here is what only the database can tell: that the database exists, the server runs, the
     * names are free.
     *
     * @param  array{mode: string, database_id?: int|string|null, server?: string|null, name?: string|null, engine?: string|null, version?: string|null}  $choice
     *
     * @throws DatabaseException
     */
    public function apply(App $app, array $choice, ?User $by = null): void
    {
        $mode = $choice['mode'];

        if (! in_array($mode, self::MODES, true)) {
            throw new DatabaseException(__('Choose how the app gets its database.'));
        }

        $this->forgetPreviousLink($app);

        match ($mode) {
            self::EXISTING => $this->existing($app, $choice, $by),
            self::NEW => $this->new($app, $choice, $by),
            self::DEDICATED => $this->dedicated($app, $choice, $by),
            self::NONE => $this->remember($app, ['mode' => self::NONE, 'status' => self::READY]),
        };

        $this->audit->record('app.database_chosen', $by, array_filter([
            'app' => $app->slug,
            'mode' => $mode,
            'server' => $this->state($app)['server'] ?? null,
            'database' => $this->state($app)['database'] ?? null,
        ], fn ($v) => $v !== null), $app);
    }

    /**
     * Finish a dedicated choice once its server runs: the first database (its user gets the rights of the
     * whole server) is made and linked. Safe to call any time; it does nothing when there is nothing to do.
     */
    public function resume(App $app, ?User $by = null): void
    {
        $state = $this->state($app);

        if (($state['mode'] ?? null) !== self::DEDICATED || ($state['status'] ?? null) !== self::PENDING) {
            return;
        }

        $server = DatabaseServer::query()->where('slug', (string) ($state['server'] ?? ''))->where('dedicated_app_id', $app->id)->first();

        if ($server === null) {
            $this->remember($app, [...$state, 'status' => self::FAILED, 'error' => __('The database server of this app is gone. Choose again.')]);

            return;
        }

        if ($server->status === DatabaseServerStatus::Failed) {
            $this->remember($app, [...$state, 'status' => self::FAILED, 'error' => $server->error ?: __('The database server could not start.')]);

            return;
        }

        if (! $server->isRunning()) {
            return;
        }

        try {
            $database = $this->databases->create($server, (string) ($state['database'] ?? $this->suggestedDatabase($app)), admin: true);
            $this->links->linkDatabase($app, $database, AppDatabaseLink::LARAVEL_PREFIX, AppDatabaseLink::STYLE_LARAVEL);
        } catch (DatabaseException $e) {
            $this->remember($app, [...$state, 'status' => self::FAILED, 'error' => $e->getMessage()]);

            return;
        }

        $this->audit->record('database.created', $by, ['server' => $server->slug, 'database' => $database->name], $app);
        $this->remember($app, [...$state, 'status' => self::READY, 'error' => null]);
    }

    /**
     * How the step stands, for the page: the choice, whether it is done, and (while a dedicated server
     * starts) what the server is doing. Never a secret.
     *
     * @return array{mode: string|null, status: string|null, error: string|null, server: string|null, server_status: string|null, database: string|null, linked: array{server: string, database: string, engine: string}|null}
     */
    public function status(App $app): array
    {
        $state = $this->state($app);
        $link = $app->databaseLinks()->where('env_style', AppDatabaseLink::STYLE_LARAVEL)->with('database.server')->first();
        $server = isset($state['server']) ? DatabaseServer::query()->where('slug', $state['server'])->first() : null;

        return [
            'mode' => $state['mode'] ?? ($link === null ? null : self::EXISTING),
            'status' => $state['status'] ?? ($link === null ? null : self::READY),
            'error' => $state['error'] ?? null,
            'server' => $state['server'] ?? null,
            'server_status' => $server?->status->value,
            'database' => $state['database'] ?? null,
            'linked' => $link === null ? null : [
                'server' => $link->database->server->name,
                'database' => $link->database->name,
                'engine' => $link->database->server->engine->label(),
            ],
        ];
    }

    /** A database name for the app: its slug with underscores, a letter first, never a name the engine keeps. */
    public function suggestedDatabase(App $app): string
    {
        $name = trim(Str::limit(str_replace('-', '_', $app->slug), 31, ''), '_');

        if (preg_match('/^[a-z]/', $name) !== 1) {
            $name = 'app_'.$name;
        }

        try {
            return DatabaseNames::database($name);
        } catch (DatabaseException) {
            return Str::limit($name.'_db', 31, '');
        }
    }

    /** A name for the app's own server. */
    public function suggestedServer(App $app): string
    {
        return Str::limit($app->slug.'-db', 60, '');
    }

    /**
     * @param  array<string, mixed>  $choice
     *
     * @throws DatabaseException
     */
    private function existing(App $app, array $choice, ?User $by): void
    {
        $database = ManagedDatabase::query()->with('server')->find((int) ($choice['database_id'] ?? 0));

        if ($database === null) {
            throw new DatabaseException(__('Choose one of the databases.'));
        }

        $this->links->linkDatabase($app, $database, AppDatabaseLink::LARAVEL_PREFIX, AppDatabaseLink::STYLE_LARAVEL);
        $this->audit->record('app.database_linked', $by, ['app' => $app->slug, 'database' => $database->name, 'server' => $database->server->slug, 'prefix' => AppDatabaseLink::LARAVEL_PREFIX], $app);
        $this->remember($app, ['mode' => self::EXISTING, 'status' => self::READY, 'server' => $database->server->slug, 'database' => $database->name]);
    }

    /**
     * @param  array<string, mixed>  $choice
     *
     * @throws DatabaseException
     */
    private function new(App $app, array $choice, ?User $by): void
    {
        $server = DatabaseServer::query()->where('slug', (string) ($choice['server'] ?? ''))->first();

        if ($server === null) {
            throw new DatabaseException(__('Choose one of the database servers.'));
        }

        $name = (string) ($choice['name'] ?? '');
        $database = $this->databases->create($server, $name === '' ? $this->suggestedDatabase($app) : $name);

        $this->audit->record('database.created', $by, ['server' => $server->slug, 'database' => $database->name], $app);

        // If linking fails the database stays on the server, for the person to use or drop.
        $this->links->linkDatabase($app, $database, AppDatabaseLink::LARAVEL_PREFIX, AppDatabaseLink::STYLE_LARAVEL);

        $this->audit->record('app.database_linked', $by, ['app' => $app->slug, 'database' => $database->name, 'server' => $server->slug, 'prefix' => AppDatabaseLink::LARAVEL_PREFIX], $app);
        $this->remember($app, ['mode' => self::NEW, 'status' => self::READY, 'server' => $server->slug, 'database' => $database->name]);
    }

    /**
     * @param  array<string, mixed>  $choice
     *
     * @throws DatabaseException
     */
    private function dedicated(App $app, array $choice, ?User $by): void
    {
        $name = (string) ($choice['name'] ?? '');
        $database = DatabaseNames::database($name === '' ? $this->suggestedDatabase($app) : $name);

        // Choosing it again reuses the server already made for this app instead of making a second one.
        $server = DatabaseServer::query()->where('dedicated_app_id', $app->id)->first();

        if ($server !== null && $server->status === DatabaseServerStatus::Failed) {
            // Choosing it again is a way to try again after a failed start.
            $this->servers->retry($server);
        }

        if ($server === null) {
            $engine = DatabaseEngine::tryFrom((string) ($choice['engine'] ?? ''));

            if ($engine === null) {
                throw new DatabaseException(__('Choose a database engine.'));
            }

            $server = $this->servers->create((string) ($choice['server_name'] ?? $this->suggestedServer($app)), $engine, (string) ($choice['version'] ?? ''));
            $server->forceFill(['dedicated_app_id' => $app->id])->save();
            $this->audit->record('database_server.created', $by, ['server' => $server->slug, 'engine' => $server->engine->value, 'version' => $server->version, 'dedicated_to' => $app->slug], $app);
        }

        $this->remember($app, ['mode' => self::DEDICATED, 'status' => self::PENDING, 'server' => $server->slug, 'database' => $database, 'error' => null]);
        $this->resume($app, $by);
    }

    /** A new choice replaces the old link (the database it pointed at stays where it is). */
    private function forgetPreviousLink(App $app): void
    {
        foreach ($app->databaseLinks()->where('env_style', AppDatabaseLink::STYLE_LARAVEL)->get() as $link) {
            $this->links->unlinkDatabase($link);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function state(App $app): array
    {
        $fresh = App::query()->whereKey($app->id)->value('stack_options');
        $options = is_string($fresh) ? json_decode($fresh, true) : $fresh;

        return is_array($options) ? ($options['database_step'] ?? []) : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function remember(App $app, array $state): void
    {
        $fresh = App::query()->whereKey($app->id)->value('stack_options');
        $options = is_string($fresh) ? json_decode($fresh, true) : $fresh;
        $options = is_array($options) ? $options : [];
        $options['database_step'] = array_filter($state, fn ($v) => $v !== null);

        $app->forceFill(['stack_options' => $options])->save();
    }
}
