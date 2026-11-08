<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerNginxController extends Controller
{
    public function index(HostDriver $host): Response
    {
        try {
            $status = $host->nginxStatus();
            $error = null;
        } catch (HostOperationFailed $e) {
            $status = null;
            $error = $e->getMessage();
        }

        return Inertia::render('server/nginx', [
            'error' => $error,
            'nginx' => $status === null ? null : [
                'available' => $status->available,
                'running' => $status->running,
                'version' => $status->version,
                'config_ok' => $status->configOk,
                'config_output' => $status->configOutput,
                'sites' => $status->sites,
            ],
        ]);
    }

    public function test(HostDriver $host): RedirectResponse
    {
        try {
            $result = $host->testNginx();
        } catch (HostOperationFailed $e) {
            return back()->withErrors(['nginx' => $e->getMessage()]);
        }

        return $result->ok
            ? back()->with('status', __('The nginx configuration is valid.'))
            : back()->withErrors(['nginx' => __('The nginx configuration has a problem: :output', ['output' => $result->output])]);
    }

    public function reload(HostDriver $host, AuditLogger $audit): RedirectResponse
    {
        try {
            $host->reloadNginx();
        } catch (HostOperationFailed $e) {
            return back()->withErrors(['nginx' => $e->getMessage()]);
        }

        $audit->record('nginx.reloaded');

        return back()->with('status', __('nginx was reloaded. Connections in progress were not dropped.'));
    }
}
