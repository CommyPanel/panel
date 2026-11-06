<?php

namespace App\Http\Controllers;

use App\Audit\AuditFilter;
use App\Audit\Severity;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'severity' => ['nullable', 'array', 'max:3'],
            'severity.*' => ['string', 'in:info,warning,danger'],
            'range' => ['nullable', 'string', 'in:today,7d,30d,year,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $filter = AuditFilter::fromInput($input);

        return Inertia::render('settings/audit-log', [
            'logs' => $filter->apply(AuditLog::query())
                ->latest('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'severity' => Severity::of($log->action),
                    'user' => $log->user_email,
                    'ip' => $log->ip_address,
                    'metadata' => $log->metadata,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
            'filters' => [
                'q' => $filter->search,
                'severity' => $filter->severities,
                'range' => $input['range'] ?? null,
                'from' => $input['from'] ?? null,
                'to' => $input['to'] ?? null,
            ],
        ]);
    }
}
