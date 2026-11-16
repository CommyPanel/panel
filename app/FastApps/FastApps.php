<?php

namespace App\FastApps;

use App\Models\App;

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
        ];

        return array_combine(array_map(fn (FastApp $a) => $a->id(), $apps), $apps);
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
                // The person's own finished apps made from the recipe this one connects to.
                'options' => App::query()->where('template', $f->appTemplate)->whereNull('setup_step')
                    ->orderBy('name')->get(['name', 'slug'])
                    ->map(fn (App $a) => ['value' => $a->slug, 'label' => $a->name])->all(),
            ], $app->fields()),
        ], self::all()));
    }
}
