<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Databases\DatabaseException;
use App\Databases\DatabaseServers;
use App\Enums\DatabaseEngine;
use App\Models\DatabaseServer;
use App\Presenters\DatabasePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseServerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('databases/index', [
            'servers' => DatabaseServer::query()->orderBy('name')->get()->map(fn (DatabaseServer $s) => DatabasePresenter::server($s))->all(),
            'engines' => collect(DatabaseEngine::cases())->map(fn (DatabaseEngine $e) => ['value' => $e->value, 'label' => $e->label(), 'versions' => $e->versions()])->all(),
        ]);
    }

    public function show(DatabaseServer $server): Response
    {
        return Inertia::render('databases/show', [
            'server' => DatabasePresenter::server($server),
            'databases' => $server->databases()->with('links.app')->orderBy('name')->get()->map(fn ($d) => DatabasePresenter::database($d))->all(),
        ]);
    }

    public function store(Request $request, DatabaseServers $servers, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'engine' => ['required', Rule::enum(DatabaseEngine::class)],
            'version' => ['required', 'string', 'max:16'],
        ]);

        try {
            $server = $servers->create($data['name'], DatabaseEngine::from($data['engine']), $data['version']);
        } catch (DatabaseException $e) {
            return back()->withErrors(['version' => $e->getMessage()]);
        }

        $audit->record('database_server.created', $request->user(), ['server' => $server->slug, 'engine' => $server->engine->value, 'version' => $server->version]);

        return redirect()->route('databases.show', $server)->with('status', "{$server->name} is being set up. The first start downloads the image and can take a few minutes.");
    }

    public function start(Request $request, DatabaseServer $server, DatabaseServers $servers, AuditLogger $audit): RedirectResponse
    {
        return $this->control($request, $server, $audit, 'started', fn () => $servers->start($server));
    }

    public function stop(Request $request, DatabaseServer $server, DatabaseServers $servers, AuditLogger $audit): RedirectResponse
    {
        return $this->control($request, $server, $audit, 'stopped', fn () => $servers->stop($server));
    }

    public function restart(Request $request, DatabaseServer $server, DatabaseServers $servers, AuditLogger $audit): RedirectResponse
    {
        return $this->control($request, $server, $audit, 'restarted', fn () => $servers->restart($server));
    }

    public function retry(Request $request, DatabaseServer $server, DatabaseServers $servers, AuditLogger $audit): RedirectResponse
    {
        return $this->control($request, $server, $audit, 'started again', fn () => $servers->retry($server));
    }

    public function destroy(Request $request, DatabaseServer $server, DatabaseServers $servers, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:'.$server->slug]], ['confirm.in' => 'Type the server name exactly to confirm.']);

        $slug = $server->slug;
        $databases = $server->databases()->pluck('name')->all();

        try {
            $servers->delete($server);
        } catch (DatabaseException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        $audit->record('database_server.deleted', $request->user(), ['server' => $slug, 'databases' => $databases]);

        return redirect()->route('databases.index')->with('status', "Database server {$slug} and its data are deleted.");
    }

    /**
     * @param  callable(): void  $action
     */
    private function control(Request $request, DatabaseServer $server, AuditLogger $audit, string $verb, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (DatabaseException $e) {
            return back()->withErrors(['database' => $e->getMessage()]);
        }

        $audit->record("database_server.{$verb}", $request->user(), ['server' => $server->slug]);

        return back()->with('status', "{$server->name} is {$verb}.");
    }
}
