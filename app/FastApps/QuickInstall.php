<?php

namespace App\FastApps;

use App\Databases\AppLinks;
use App\Databases\DatabaseException;
use App\Databases\ManageDatabases;
use App\Deploy\AppVolumes;
use App\Deploy\Traffic\SaveNginxCustom;
use App\Enums\DatabaseServerStatus;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\Host\Exceptions\HostOperationFailed;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Setting up an app from a quick-install recipe (the only code that does): the draft app with its
 * volumes and settings at the first wizard step, the database server chosen at the second, and at the
 * end the database, its link and the app's nginx extras. After the first release went live the
 * one-time variables (the first user's password) are removed.
 */
class QuickInstall
{
    public function __construct(
        private readonly AppVolumes $volumes,
        private readonly ManageDatabases $databases,
        private readonly AppLinks $links,
        private readonly SaveNginxCustom $nginx,
    ) {}

    /**
     * The first step: the app, its settings and its volumes. Nothing runs yet.
     *
     * @param  array<string, string>  $settings  the validated values of the template's fields
     */
    public function create(FastApp $template, string $name, array $settings): App
    {
        return DB::transaction(function () use ($template, $name, $settings): App {
            $app = App::query()->create([
                'name' => $name,
                'slug' => App::generateSlug($name),
                'source_type' => SourceType::Template,
                'template' => $template->id(),
                // What is not secret, for showing later. The password and the salts are only in the variables.
                'template_settings' => array_diff_key($settings, ['admin_password' => true]),
                'stack' => $template->stack(),
                'container_port' => $template->containerPort(),
                'health_path' => $template->healthPath(),
                'memory_limit_mb' => $template->memoryLimitMb(),
                'git_branch' => 'main',
                // There is no repository to verify: the recipe is the source.
                'source_verified_at' => now(),
                'setup_step' => SetupStep::Database,
            ]);

            foreach ($template->environment($settings) as $key => $value) {
                AppEnvVar::query()->create(['app_id' => $app->id, 'key' => $key, 'value' => $value]);
            }

            try {
                foreach ($template->volumePaths() as $path) {
                    $this->volumes->add($app, $path);
                }
            } catch (InvalidArgumentException $e) {
                // A recipe's own paths are fixed; this only happens if one is wrong.
                throw new \LogicException($e->getMessage(), 0, $e);
            }

            return $app;
        });
    }

    /**
     * The running database servers that work with the recipe.
     *
     * @return list<DatabaseServer>
     */
    public function servers(FastApp $template): array
    {
        $engines = array_map(fn ($e) => $e->value, $template->engines());

        return array_values(DatabaseServer::query()
            ->whereIn('engine', $engines)
            ->orderBy('name')
            ->get()
            ->all());
    }

    /**
     * @throws DatabaseException
     */
    public function chooseServer(App $app, DatabaseServer $server): void
    {
        $template = $this->templateOf($app);

        if (! in_array($server->engine, $template->engines(), true)) {
            throw new DatabaseException(__(':name does not work with :engine. Choose a MariaDB or MySQL server.', ['name' => $template->name(), 'engine' => $server->engine->label()]));
        }

        if ($server->status === DatabaseServerStatus::Failed) {
            throw new DatabaseException(__('That database server failed to start. Choose another one, or try it again on the Databases page.'));
        }

        $app->forceFill(['template_database_server_id' => $server->id])->save();
    }

    /**
     * Whether the chosen server can be used now (it is running).
     */
    public function serverReady(App $app): bool
    {
        $server = $app->template_database_server_id === null ? null : DatabaseServer::query()->find($app->template_database_server_id);

        return $server !== null && $server->isRunning();
    }

    /**
     * The last step of the wizard, just before the first deployment: the database and its link, and the
     * extras for the app's nginx site. If something fails, what was made is taken back.
     *
     * @throws DatabaseException
     */
    public function finish(App $app, ?User $by = null): void
    {
        $template = $this->templateOf($app);
        $server = $app->template_database_server_id === null ? null : DatabaseServer::query()->find($app->template_database_server_id);

        if ($server === null) {
            throw new DatabaseException(__('Choose the database server first.'));
        }

        if (! $server->isRunning()) {
            throw new DatabaseException(__('The database server is not running yet. Wait until it is, or choose another one.'));
        }

        $database = $this->databases->create($server, $this->databaseName($server, $app, $template));

        try {
            $this->links->linkDatabase($app, $database, $template->databasePrefix());
        } catch (DatabaseException $e) {
            $this->databases->drop($database);

            throw $e;
        }

        $custom = $template->nginxCustom();

        if ($custom !== null) {
            try {
                ($this->nginx)($app, $custom, $by);
            } catch (HostOperationFailed) {
                // The site works without the extras (only large uploads fail); they can be set on the Nginx tab.
            }
        }
    }

    /**
     * After the first release went live: the variables that were only for the first start go.
     * They are deleted without touching the app's "changes to apply" mark, because nothing needs applying.
     */
    public function afterLive(App $app): void
    {
        $template = FastApps::find($app->template);

        if ($template === null) {
            return;
        }

        AppEnvVar::query()->where('app_id', $app->id)->whereIn('key', $template->oneTimeVariables())->delete();
    }

    private function templateOf(App $app): FastApp
    {
        $template = FastApps::find($app->template);

        if ($template === null) {
            throw new DatabaseException(__('This quick-install app is not known to this version of CommyPanel.'));
        }

        return $template;
    }

    /**
     * A database name from the app's slug: lowercase letters, digits and underscores, starting with a
     * letter, and free on the server.
     */
    private function databaseName(DatabaseServer $server, App $app, FastApp $template): string
    {
        // Starting with the recipe's id keeps it a valid name and away from the names the server reserves.
        $base = $template->id().'_'.str_replace('-', '_', $app->slug);
        $base = rtrim(substr($base, 0, 26), '_');
        $name = $base;

        for ($n = 2; $server->databases()->where('name', $name)->exists(); $n++) {
            $name = $base.'_'.$n;
        }

        return $name;
    }

    /** The database of a quick-install app, if it has one yet. */
    public function databaseOf(App $app): ?ManagedDatabase
    {
        $link = $app->databaseLinks()->with('database.server')->first();

        return $link?->database;
    }
}
