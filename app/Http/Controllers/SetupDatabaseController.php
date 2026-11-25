<?php

namespace App\Http\Controllers;

use App\Auth\Permissions;
use App\Databases\DatabaseException;
use App\Databases\SetupAppDatabase;
use App\Enums\DatabaseEngine;
use App\Enums\SetupStep;
use App\Models\App;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The database step of the Laravel wizard (JSON; the step component calls it). GET answers with what
 * can be chosen and how the choice stands (and, for a dedicated server that is still starting, moves it
 * along); PUT carries a choice out. Draft apps past the source step only.
 */
class SetupDatabaseController extends Controller
{
    public function show(Request $request, App $app, SetupAppDatabase $setup): JsonResponse
    {
        $this->assertDraft($app);

        // Polling is what finishes a dedicated server's first database once the server runs.
        $setup->resume($app, $request->user());

        $user = $request->user();
        $canView = $user->can(Permissions::DATABASES_VIEW);

        // A server made for another app is not for sharing; the app's own dedicated server is not offered either
        // (it is chosen with "dedicated").
        $servers = $canView
            ? DatabaseServer::query()->whereNull('dedicated_app_id')->orderBy('name')->get()
            : collect();

        return response()->json([
            'choice' => $setup->status($app),
            'servers' => $servers->map(fn (DatabaseServer $s) => [
                'slug' => $s->slug,
                'name' => $s->name,
                'engine' => $s->engine->value,
                'engine_label' => $s->engine->label(),
                'version' => $s->version,
                'running' => $s->isRunning(),
            ])->values()->all(),
            'databases' => $canView
                ? ManagedDatabase::query()->with('server')->whereNull('restore_status')->orderBy('name')->get()
                    ->filter(fn (ManagedDatabase $d) => $d->server->dedicated_app_id === null && $d->server->isRunning())
                    ->map(fn (ManagedDatabase $d) => [
                        'id' => $d->id,
                        'name' => $d->name,
                        'server' => $d->server->slug,
                        'server_name' => $d->server->name,
                        'engine_label' => $d->server->engine->label(),
                        'version' => $d->server->version,
                    ])->values()->all()
                : [],
            'engines' => collect(DatabaseEngine::cases())->map(fn (DatabaseEngine $e) => ['value' => $e->value, 'label' => $e->label(), 'versions' => $e->versions()])->all(),
            'suggested' => ['database' => $setup->suggestedDatabase($app), 'server' => $setup->suggestedServer($app)],
            'can' => [
                'link' => $user->can(Permissions::APPS_LINKS),
                'manage' => $user->can(Permissions::DATABASES_MANAGE),
            ],
        ]);
    }

    public function update(Request $request, App $app, SetupAppDatabase $setup): JsonResponse
    {
        $this->assertDraft($app);

        $data = $request->validate([
            'mode' => ['required', Rule::in(SetupAppDatabase::MODES)],
            'database_id' => ['required_if:mode,'.SetupAppDatabase::EXISTING, 'nullable', 'integer'],
            'server' => ['required_if:mode,'.SetupAppDatabase::NEW, 'nullable', 'string', 'max:60'],
            'name' => ['nullable', 'string', 'max:60'],
            'engine' => ['required_if:mode,'.SetupAppDatabase::DEDICATED, 'nullable', Rule::enum(DatabaseEngine::class)],
            'version' => ['required_if:mode,'.SetupAppDatabase::DEDICATED, 'nullable', 'string', 'max:16'],
            'server_name' => ['nullable', 'string', 'min:2', 'max:60'],
        ]);

        $user = $request->user();
        $mode = $data['mode'];

        // Linking an app to a database hands the app its credentials; making servers and databases is the Databases permission.
        abort_if($mode !== SetupAppDatabase::NONE && ! $user->can(Permissions::APPS_LINKS), 403);
        abort_if(in_array($mode, [SetupAppDatabase::NEW, SetupAppDatabase::DEDICATED], true) && ! $user->can(Permissions::DATABASES_MANAGE), 403);

        // A database on a server that belongs to another app is never offered, so it is not accepted either.
        if ($mode === SetupAppDatabase::EXISTING) {
            $database = ManagedDatabase::query()->with('server')->find((int) $data['database_id']);

            if ($database === null || $database->server->dedicated_app_id !== null) {
                throw ValidationException::withMessages(['database_id' => __('Choose one of the databases.')]);
            }
        }

        if ($mode === SetupAppDatabase::NEW) {
            $server = DatabaseServer::query()->where('slug', (string) $data['server'])->first();

            if ($server === null || $server->dedicated_app_id !== null) {
                throw ValidationException::withMessages(['server' => __('Choose one of the database servers.')]);
            }
        }

        try {
            $setup->apply($app, $data, $user);
        } catch (DatabaseException $e) {
            throw ValidationException::withMessages([$this->fieldFor($mode) => $e->getMessage()]);
        }

        return response()->json(['choice' => $setup->status($app->fresh())]);
    }

    /** Which field a refusal belongs under. */
    private function fieldFor(string $mode): string
    {
        return match ($mode) {
            SetupAppDatabase::EXISTING => 'database_id',
            SetupAppDatabase::NEW, SetupAppDatabase::DEDICATED => 'name',
            default => 'mode',
        };
    }

    private function assertDraft(App $app): void
    {
        abort_unless($app->setup_step !== null && $app->setup_step !== SetupStep::Source, 404);
    }
}
