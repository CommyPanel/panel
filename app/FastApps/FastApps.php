<?php

namespace App\FastApps;

/** The quick-install apps CommyPanel knows. Adding one is a new class here. */
final class FastApps
{
    /**
     * @return array<string, FastApp>
     */
    public static function all(): array
    {
        $apps = [new WordPress];

        return array_combine(array_map(fn (FastApp $a) => $a->id(), $apps), $apps);
    }

    public static function find(?string $id): ?FastApp
    {
        return $id === null ? null : (self::all()[$id] ?? null);
    }

    /**
     * What the create page needs to show them, without anything secret.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_values(array_map(fn (FastApp $app) => [
            'id' => $app->id(),
            'name' => $app->name(),
            'description' => $app->description(),
            'engines' => array_map(fn ($e) => $e->value, $app->engines()),
            'fields' => array_map(fn (Field $f) => $f->toArray(), $app->fields()),
        ], self::all()));
    }
}
