<?php

namespace App\Deploy;

use App\Enums\ProcessKind;
use App\Enums\Stack;
use App\Models\App;
use App\Models\AppProcess;
use Illuminate\Support\Facades\DB;

/**
 * The extra processes of an app (see ReleaseProcesses for how they run): making the first list from what
 * the wizard found in the code, replacing the list from the Services page, and showing it.
 */
class AppProcesses
{
    /** Names a process may not have: the web process, and the name of the release task. */
    public const RESERVED = ['web', 'release'];

    /**
     * Whether the app is a Laravel app (or is being made as one): it always has a scheduler row.
     */
    public static function isLaravel(App $app): bool
    {
        return $app->stack === Stack::Laravel || $app->stack_hint === Stack::Laravel;
    }

    /**
     * The first list of a new app: the scheduler (on), then each service the inspector found (off, the person
     * switches on what they want). A service the panel cannot run yet is listed off, with its note on the page.
     * Does nothing when the app already has a list, so a person's changes are never undone.
     *
     * @param  list<array<string, mixed>>  $detectedServices  from `apps.detected['services']`: kind, name, detected_by, default_command, supported, note
     */
    public function seedFor(App $app, array $detectedServices): void
    {
        if ($app->processes()->exists()) {
            return;
        }

        DB::transaction(function () use ($app, $detectedServices) {
            $sort = 0;

            if (self::isLaravel($app)) {
                $this->create($app, ProcessKind::Scheduler, 'scheduler', null, true, null, $sort++);
            }

            $names = ['scheduler' => true];

            foreach ($detectedServices as $service) {
                $kind = ProcessKind::tryFrom((string) ($service['kind'] ?? '')) ?? ProcessKind::Custom;
                $name = $this->name((string) ($service['name'] ?? $kind->defaultName()), $kind);

                if (isset($names[$name])) {
                    continue;
                }

                $names[$name] = true;
                $default = isset($service['default_command']) && is_string($service['default_command']) ? trim($service['default_command']) : null;
                // The usual command of the kind is not stored, so a newer panel's default applies; anything else is.
                $command = $default === null || $default === '' || $default === $kind->defaultCommand() ? null : $default;

                $this->create($app, $kind, $name, $command, false, isset($service['detected_by']) ? (string) $service['detected_by'] : null, $sort++);
            }
        });
    }

    /**
     * Replace the list with the person's: rows are matched by name, new ones are made, the others are removed.
     * A Laravel app keeps its scheduler row (it can be switched off, not removed).
     *
     * @param  list<array<string, mixed>>  $rows  already validated
     */
    public function replace(App $app, array $rows): void
    {
        DB::transaction(function () use ($app, $rows) {
            $names = array_column($rows, 'name');

            $app->processes()->whereNotIn('name', $names)
                ->when(self::isLaravel($app), fn ($q) => $q->where('name', '!=', 'scheduler'))
                ->get()->each->delete();

            foreach ($rows as $i => $row) {
                $kind = ProcessKind::from((string) $row['kind']);
                $command = isset($row['command']) ? trim((string) $row['command']) : '';

                AppProcess::query()->updateOrCreate(['app_id' => $app->id, 'name' => $row['name']], [
                    'kind' => $kind,
                    'command' => $command === '' || $command === $kind->defaultCommand() ? null : $command,
                    'enabled' => (bool) ($row['enabled'] ?? false),
                    'replicas' => $kind->mayReplicate() ? max(1, min(10, (int) ($row['replicas'] ?? 1))) : 1,
                    'overlap' => in_array($row['overlap'] ?? null, [AppProcess::OVERLAP, AppProcess::STOP_FIRST], true) ? $row['overlap'] : $kind->defaultOverlap(),
                    'memory_limit_mb' => isset($row['memory_limit_mb']) && $row['memory_limit_mb'] !== '' ? (int) $row['memory_limit_mb'] : null,
                    'stop_grace_seconds' => (int) ($row['stop_grace_seconds'] ?? $kind->defaultGraceSeconds()),
                    'sort' => $i,
                ]);
            }
        });
    }

    /**
     * What the Services card shows, nothing secret: the processes with the default command beside the person's.
     *
     * @return array<int, array<string, mixed>>
     */
    public function present(App $app): array
    {
        $found = [];

        foreach ((array) ($app->detected['services'] ?? []) as $service) {
            if (is_array($service) && isset($service['name'])) {
                $found[(string) $service['name']] = $service;
            }
        }

        return $app->processes()->get()->map(function (AppProcess $p) use ($found, $app) {
            $service = $found[$p->name] ?? null;

            return [
                'name' => $p->name,
                'kind' => $p->kind->value,
                'kind_label' => $p->kind->label(),
                'command' => $p->command,
                'default_command' => $p->kind->defaultCommand() ?? ($service['default_command'] ?? null),
                'enabled' => $p->enabled,
                'replicas' => $p->replicas,
                'may_replicate' => $p->kind->mayReplicate(),
                'overlap' => $p->overlap,
                'memory_limit_mb' => $p->memory_limit_mb,
                'stop_grace_seconds' => $p->stop_grace_seconds,
                'detected_by' => $p->detected_by,
                'supported' => (bool) ($service['supported'] ?? true),
                'note' => isset($service['note']) ? (string) $service['note'] : null,
                'removable' => ! ($p->name === 'scheduler' && self::isLaravel($app)),
            ];
        })->values()->all();
    }

    /** A name fit for a container: lowercase letters, digits and dashes, starting with a letter. */
    public function name(string $wanted, ProcessKind $kind): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($wanted)), '-');
        $name = preg_replace('/-\d+$/', '', $name) ?? $name;
        $name = $name === '' || ! ctype_alpha($name[0]) ? $kind->defaultName() : substr($name, 0, 31);

        return in_array($name, self::RESERVED, true) ? $kind->defaultName().'-app' : $name;
    }

    private function create(App $app, ProcessKind $kind, string $name, ?string $command, bool $enabled, ?string $detectedBy, int $sort): void
    {
        AppProcess::query()->create([
            'app_id' => $app->id,
            'name' => $name,
            'kind' => $kind,
            'command' => $command,
            'enabled' => $enabled,
            'replicas' => 1,
            'overlap' => $kind->defaultOverlap(),
            'stop_grace_seconds' => $kind->defaultGraceSeconds(),
            'detected_by' => $detectedBy,
            'sort' => $sort,
        ]);
    }
}
