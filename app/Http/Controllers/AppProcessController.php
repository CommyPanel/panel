<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\AppProcesses;
use App\Http\Requests\UpdateProcessesRequest;
use App\Models\App;
use Illuminate\Http\RedirectResponse;

/**
 * The Services of an app (Laravel: scheduler, queue workers, Horizon ...): which extra processes run beside the
 * web one, and with what command. They take effect with the next deployment or "Apply changes".
 */
class AppProcessController extends Controller
{
    public function update(UpdateProcessesRequest $request, App $app, AppProcesses $processes, AuditLogger $audit): RedirectResponse
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($request->validated('processes'));

        $processes->replace($app, $rows);

        // Names and on/off only: a command can hold a secret.
        $audit->record('app.processes_changed', $request->user(), [
            'app' => $app->slug,
            'processes' => array_map(fn (array $r) => ['name' => $r['name'], 'enabled' => (bool) $r['enabled']], $rows),
        ], $app);

        return back()->with('status', __('Services saved. They apply from the next deployment, or press Apply changes.'));
    }
}
