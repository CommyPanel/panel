<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Databases\AppLinks;
use App\Databases\DatabaseException;
use App\Dns\PanelDomain;
use App\Models\App;
use App\Models\AppDatabaseLink;
use App\Models\AppLink;
use App\Models\ManagedDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Links from an app to a database or to another app. A new link applies from
 * the app's next deployment, like an environment variable.
 */
class AppLinkController extends Controller
{
    public function index(App $app): JsonResponse
    {
        $linkedDatabases = $app->databaseLinks()->pluck('managed_database_id');
        $linkedApps = $app->links()->pluck('target_app_id');

        return response()->json([
            'databases' => $app->databaseLinks()->with('database.server')->get()->map(fn (AppDatabaseLink $l) => [
                'id' => $l->id,
                'prefix' => $l->env_prefix,
                'database' => $l->database->name,
                'server' => $l->database->server->name,
                'server_slug' => $l->database->server->slug,
                'engine' => $l->database->server->engine->label(),
                'running' => $l->database->server->isRunning(),
            ])->all(),
            'apps' => $app->links()->with('target')->get()->map(fn (AppLink $l) => [
                'id' => $l->id,
                'prefix' => $l->env_prefix,
                'app' => $l->target->name,
                'slug' => $l->target->slug,
            ])->all(),
            'used_by' => $app->linkedFrom()->with('app')->get()->map(fn (AppLink $l) => ['app' => $l->app->name, 'slug' => $l->app->slug])->all(),
            'available_databases' => ManagedDatabase::query()->with('server')->whereNotIn('id', $linkedDatabases)->orderBy('name')->get()
                ->filter(fn (ManagedDatabase $d) => $d->server->isRunning())
                ->map(fn (ManagedDatabase $d) => ['id' => $d->id, 'label' => "{$d->name} on {$d->server->name} ({$d->server->engine->label()} {$d->server->version})"])->values()->all(),
            'available_apps' => App::query()->whereKeyNot($app->id)->whereNotIn('id', $linkedApps)->where('slug', '!=', PanelDomain::SLUG)->whereNull('setup_step')->orderBy('name')->get(['id', 'name', 'slug'])
                ->map(fn (App $a) => ['slug' => $a->slug, 'name' => $a->name])->all(),
        ]);
    }

    public function storeDatabase(Request $request, App $app, AppLinks $links, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'database_id' => ['required', 'integer', 'exists:managed_databases,id'],
            'env_prefix' => ['required', 'string', 'max:31'],
        ]);

        $database = ManagedDatabase::query()->where('id', $data['database_id'])->with('server')->firstOrFail();

        try {
            $link = $links->linkDatabase($app, $database, $data['env_prefix']);
        } catch (DatabaseException $e) {
            return back()->withErrors(['link' => $e->getMessage()]);
        }

        $audit->record('app.database_linked', $request->user(), ['app' => $app->slug, 'database' => $database->name, 'server' => $database->server->slug, 'prefix' => $link->env_prefix], $app);

        return back()->with('status', "{$app->name} can reach {$database->name} from its next deployment. The connection is added as {$link->env_prefix}_URL and related variables.");
    }

    public function storeApp(Request $request, App $app, AppLinks $links, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'exists:apps,slug'],
            'env_prefix' => ['required', 'string', 'max:31'],
        ]);

        $target = App::query()->where('slug', $data['target'])->firstOrFail();

        try {
            $link = $links->linkApp($app, $target, $data['env_prefix']);
        } catch (DatabaseException $e) {
            return back()->withErrors(['link' => $e->getMessage()]);
        }

        $audit->record('app.app_linked', $request->user(), ['app' => $app->slug, 'target' => $target->slug, 'prefix' => $link->env_prefix], $app);

        return back()->with('status', "{$app->name} can reach {$target->name} from its next deployment, at http://{$target->slug}:{$target->container_port}. {$target->name} needs a new deployment to be reachable by that name.");
    }

    public function destroyDatabase(Request $request, App $app, AppDatabaseLink $databaseLink, AppLinks $links, AuditLogger $audit): RedirectResponse
    {
        $name = $databaseLink->database->name;
        $links->unlinkDatabase($databaseLink);

        $audit->record('app.database_unlinked', $request->user(), ['app' => $app->slug, 'database' => $name], $app);

        return back()->with('status', "{$app->name} no longer reaches {$name} from its next deployment.");
    }

    public function destroyApp(Request $request, App $app, AppLink $appLink, AppLinks $links, AuditLogger $audit): RedirectResponse
    {
        $target = $appLink->target->slug;
        $links->unlinkApp($appLink);

        $audit->record('app.app_unlinked', $request->user(), ['app' => $app->slug, 'target' => $target], $app);

        return back()->with('status', "{$app->name} no longer reaches {$target} from its next deployment.");
    }
}
