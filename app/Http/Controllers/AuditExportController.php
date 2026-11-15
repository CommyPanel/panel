<?php

namespace App\Http\Controllers;

use App\Audit\AuditFilter;
use App\Audit\AuditLogger;
use App\Audit\Severity;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads the audit log with the filters on screen, as CSV or JSON Lines-free JSON. Needs
 * `audit.export` and a recent password; the export itself is audited. Capped, newest first, so a
 * huge log cannot tie up a request.
 */
class AuditExportController extends Controller
{
    public const MAX_ROWS = 50000;

    public function __invoke(Request $request, AuditLogger $audit): StreamedResponse
    {
        $input = $request->validate([
            'format' => ['required', 'in:csv,json'],
            'q' => ['nullable', 'string', 'max:100'],
            'severity' => ['nullable', 'array', 'max:3'],
            'severity.*' => ['string', 'in:info,warning,danger'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $filter = AuditFilter::fromInput($input);
        $format = $input['format'];
        $query = $filter->apply(AuditLog::query())->latest('id')->limit(self::MAX_ROWS);

        $audit->record('audit.exported', null, [
            'format' => $format,
            'filters' => array_filter(['q' => $filter->search, 'severity' => $filter->severities, 'from' => $input['from'] ?? null, 'to' => $input['to'] ?? null]),
        ]);

        $name = 'commypanel-audit-'.now()->format('Ymd-His').'.'.$format;

        return response()->streamDownload(function () use ($query, $format): void {
            $out = fopen('php://output', 'w') ?: throw new RuntimeException('Cannot open the output stream.');

            if ($format === 'csv') {
                fputcsv($out, ['time', 'severity', 'user', 'action', 'ip', 'user_agent', 'details'], ',', '"', '');
            } else {
                fwrite($out, "[\n");
            }

            $first = true;

            foreach ($query->lazyById(1000, 'id') as $log) {
                $row = [
                    'time' => $log->created_at->toIso8601String(),
                    'severity' => Severity::of($log->action),
                    'user' => $log->user_email,
                    'action' => $log->action,
                    'ip' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'details' => $log->metadata,
                ];

                if ($format === 'csv') {
                    $row['details'] = $row['details'] === null ? '' : json_encode($row['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    fputcsv($out, array_map(self::safe(...), $row), ',', '"', '');
                } else {
                    fwrite($out, ($first ? '' : ",\n").json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $first = false;
                }
            }

            if ($format === 'json') {
                fwrite($out, "\n]\n");
            }

            fclose($out);
        }, $name, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** A spreadsheet runs a cell that starts with = + - @ as a formula: prefix those. */
    private static function safe(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
