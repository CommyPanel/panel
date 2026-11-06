<?php

namespace App\Http\Controllers;

use App\Audit\Severity;
use App\Models\AuditLog;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/audit-log', [
            'logs' => AuditLog::query()
                ->latest('id')
                ->paginate(25)
                ->through(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'severity' => Severity::of($log->action),
                    'user' => $log->user_email,
                    'ip' => $log->ip_address,
                    'metadata' => $log->metadata,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
        ]);
    }
}
