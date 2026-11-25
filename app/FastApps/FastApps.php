<?php

namespace App\FastApps;

use App\Auth\Permissions;
use App\Enums\DatabaseEngine;
use App\Models\App;
use App\Models\ManagedDatabase;
use Illuminate\Support\Facades\Gate;

/** The quick-install apps CommyPanel knows. Adding one is a new class here. */
final class FastApps
{
    /**
     * @return array<string, FastApp>
     */
    public static function all(): array
    {
        $apps = [
            new Meilisearch, new Typesense, new Grafana, new Elasticsearch, new Kibana,
            new UptimeKuma, new Mailpit, new Gotenberg, new Redis, new N8n, new Garage,
            new Vaultwarden, new Forgejo, new Umami, new Ntfy,
        ];

        return array_combine(array_map(fn (FastApp $a) => $a->id(), $apps), $apps);
    }

    /**
     * What a person may choose for one field: their own finished apps made from a recipe, or the
     * PostgreSQL databases that are ready (only for people who may link apps).
     *
     * @return array<int, array{value: string, label: string}>
     */
    private static function options(Field $f): array
    {
        if ($f->type === 'database') {
            if (! Gate::allows(Permissions::APPS_LINKS)) {
                return [];
            }

            return ManagedDatabase::query()->with('server')->whereNull('restore_status')->orderBy('name')->get()
                ->filter(fn (ManagedDatabase $d) => $d->server->engine === DatabaseEngine::Postgres && $d->server->isRunning())
                ->map(fn (ManagedDatabase $d) => ['value' => (string) $d->id, 'label' => "{$d->name} on {$d->server->name} ({$d->server->engine->label()} {$d->server->version})"])
                ->values()->all();
        }

        // The person's own finished apps made from the recipe this one connects to.
        return App::query()->where('template', $f->appTemplate)->whereNull('setup_step')
            ->orderBy('name')->get(['name', 'slug'])
            ->map(fn (App $a) => ['value' => $a->slug, 'label' => $a->name])->all();
    }

    public static function find(?string $id): ?FastApp
    {
        return $id === null ? null : (self::all()[$id] ?? null);
    }

    /**
     * What the create page needs to show, without anything secret.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_values(array_map(fn (FastApp $app) => [
            'id' => $app->id(),
            'name' => $app->name(),
            'description' => $app->description(),
            'warnings' => $app->warnings(),
            'memory_mb' => $app->memoryLimitMb(),
            'min_memory_mb' => $app->minimumMemoryMb(),
            'guide' => Guide::for($app),
            'fields' => array_map(fn (Field $f) => [
                'key' => $f->key,
                'label' => $f->label,
                'hint' => $f->hint,
                'options' => self::options($f),
            ], $app->fields()),
        ], self::all()));
    }
}
