<?php

namespace App\Http\Controllers;

use App\Deploy\Traffic\AppRouting;
use App\Deploy\Traffic\SaveNginxCustom;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Http\Requests\AppNginxRequest;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * An app's custom nginx directives: the editor loads them (with the generated
 * config next to them, read only) and saves them.
 */
class AppNginxController extends Controller
{
    public function show(App $app, AppRouting $routing, HostDriver $host): JsonResponse
    {
        $site = $routing->siteFor($app);
        $generated = null;
        $problem = null;

        if ($site !== null) {
            try {
                $generated = $host->renderNginxSite($site);
            } catch (HostOperationFailed $e) {
                $problem = self::message($e);
            }
        }

        return response()->json([
            'custom' => $app->nginx_custom ?? '',
            'active' => $site !== null,
            'inactive_reason' => $site === null ? $this->inactiveReason($app) : null,
            'generated' => $generated,
            'problem' => $problem,
        ]);
    }

    public function update(AppNginxRequest $request, App $app, SaveNginxCustom $save): RedirectResponse
    {
        try {
            $live = $save($app, $request->input('custom'), $request->user());
        } catch (HostOperationFailed $e) {
            return back()->withErrors(['custom' => self::message($e)]);
        }

        return back()->with('status', $live
            ? 'Saved. nginx accepted the configuration and reloaded.'
            : 'Saved. It is applied once the app has a domain and a live release.');
    }

    private function inactiveReason(App $app): string
    {
        $missing = [];

        if ($app->active_deployment_id === null) {
            $missing[] = 'a live release';
        }

        if (! $app->domains()->exists()) {
            $missing[] = 'a domain';
        }

        return 'The app has no nginx site yet: it needs '.implode(' and ', $missing).'.';
    }

    /**
     * The agent's explanation without the transport prefix.
     */
    private static function message(HostOperationFailed $e): string
    {
        return (string) preg_replace('/^Agent error: /', '', $e->getMessage());
    }
}
