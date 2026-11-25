<?php

namespace App\FastApps;

use App\Databases\AppLinks;
use App\Databases\DatabaseException;
use App\Deploy\AppVolumes;
use App\Enums\DatabaseEngine;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\Enums\Stack;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\ManagedDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Setting up an app from a quick-install recipe (the only code that does): the app with its volumes,
 * generated variables and, for Kibana, the link to its Elasticsearch app (for Umami, to its database). It lands at the wizard's
 * domain step; after the first release went live the variables only the first start needed are removed.
 */
class QuickInstall
{
    public function __construct(
        private readonly AppVolumes $volumes,
        private readonly AppLinks $links,
    ) {}

    /**
     * @param  array<string, string>  $settings  the validated values of the recipe's fields
     *
     * @throws DatabaseException when the link to the other app cannot be made
     */
    public function create(FastApp $template, string $name, array $settings, ?int $memoryLimitMb = null): App
    {
        return DB::transaction(function () use ($template, $name, $settings, $memoryLimitMb): App {
            $app = App::query()->create([
                'name' => $name,
                'slug' => App::generateSlug($name),
                'source_type' => SourceType::Template,
                'template' => $template->id(),
                // What was chosen, for showing later; secrets are only in the variables.
                'template_settings' => $settings,
                'stack' => Stack::Template,
                'container_port' => $template->containerPort(),
                'health_path' => $template->healthPath(),
                'health_timeout' => $template->healthTimeout(),
                'deploy_strategy' => $template->deployStrategy(),
                // What the person chose on the create page, else the recipe's own default.
                'memory_limit_mb' => $memoryLimitMb ?? $template->memoryLimitMb(),
                'git_branch' => 'main',
                // There is no repository to verify: the recipe is the source.
                'source_verified_at' => now(),
                'setup_step' => SetupStep::Domain,
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

            $requires = $template->requires();

            if ($requires !== null) {
                $target = App::query()->where('slug', $settings[$template->fields()[0]->key] ?? '')
                    ->where('template', $requires)->whereNull('setup_step')->first();

                if ($target === null) {
                    throw new DatabaseException(__('Choose one of your :name apps first.', ['name' => FastApps::find($requires)?->name() ?? $requires]));
                }

                $this->links->linkApp($app, $target, 'ES');
            }

            foreach ($template->fields() as $field) {
                if ($field->type !== 'database' || $field->prefix === null) {
                    continue;
                }

                $database = ManagedDatabase::query()->with('server')->find((int) ($settings[$field->key] ?? 0));

                if ($database === null || $database->server->engine !== DatabaseEngine::Postgres) {
                    throw new DatabaseException(__('Choose one of your PostgreSQL databases.'));
                }

                $this->links->linkDatabase($app, $database, $field->prefix);
            }

            return $app;
        });
    }

    /**
     * After the first release went live: the variables that were only for the first start go.
     * They are deleted without touching the app's "changes to apply" mark, because nothing needs applying.
     */
    public function afterLive(App $app): void
    {
        $template = FastApps::find($app->template);

        if ($template === null || $template->oneTimeVariables() === []) {
            return;
        }

        AppEnvVar::query()->where('app_id', $app->id)->whereIn('key', $template->oneTimeVariables())->delete();
    }
}
