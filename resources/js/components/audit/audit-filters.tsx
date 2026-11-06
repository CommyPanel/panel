import { router } from '@inertiajs/react';
import { OctagonAlert, Search, TriangleAlert, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AuditLogController from '@/actions/App/Http/Controllers/AuditLogController';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type Severity = 'danger' | 'warning' | 'info';
export type Range = 'today' | '7d' | '30d' | 'year' | 'custom';

export type AuditFilters = {
    q: string;
    severity: Severity[];
    range: Range | null;
    from: string | null;
    to: string | null;
};

const RANGES: { id: Range | null; label: string }[] = [
    { id: null, label: 'All time' },
    { id: 'today', label: 'Today' },
    { id: '7d', label: 'Last 7 days' },
    { id: '30d', label: 'Last month' },
    { id: 'year', label: 'Last year' },
    { id: 'custom', label: 'Custom' },
];

const GRADES: {
    id: Severity;
    label: string;
    hint: string;
    icon?: typeof OctagonAlert;
    on: string;
}[] = [
    {
        id: 'danger',
        label: 'High risk',
        hint: 'Exposes something or destroys data or access',
        icon: OctagonAlert,
        on: 'btn-error',
    },
    {
        id: 'warning',
        label: 'Sensitive',
        hint: 'Secrets seen, access or settings changed, a failure',
        icon: TriangleAlert,
        on: 'btn-warning',
    },
    {
        id: 'info',
        label: 'Routine',
        hint: 'Everything else',
        on: 'btn-neutral',
    },
];

/** The start of a local day, as the instant the server compares with. */
function startOf(date: string): string {
    return new Date(`${date}T00:00:00`).toISOString();
}

function endOf(date: string): string {
    return new Date(`${date}T23:59:59.999`).toISOString();
}

/** A calendar day in the browser's own time zone, as yyyy-mm-dd. */
function day(iso: string | null): string {
    if (!iso) return '';

    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function presetFrom(range: Range): string {
    const now = new Date();

    if (range === 'today') {
        now.setHours(0, 0, 0, 0);
    } else if (range === '7d') {
        now.setDate(now.getDate() - 7);
    } else if (range === '30d') {
        now.setDate(now.getDate() - 30);
    } else {
        now.setFullYear(now.getFullYear() - 1);
    }

    return now.toISOString();
}

/**
 * The filter bar of the audit log: search, a time range and the grade of the entries. Every
 * change asks the server again, so the filters apply to all pages and the address can be shared.
 */
export function AuditFilterBar({
    filters,
    total,
}: {
    filters: AuditFilters;
    total: number;
}) {
    const [search, setSearch] = useState(filters.q);
    const [from, setFrom] = useState(day(filters.from));
    const [to, setTo] = useState(day(filters.to));
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Follow the server when the filters change from outside (back button, a cleared bar).
    useEffect(() => {
        setSearch(filters.q);
        setFrom(day(filters.from));
        setTo(day(filters.to));
    }, [filters.q, filters.from, filters.to]);

    useEffect(
        () => () => {
            if (timer.current) clearTimeout(timer.current);
        },
        [],
    );

    const go = (next: Partial<AuditFilters>) => {
        const f = { ...filters, q: search, ...next };
        const data: Record<string, string | string[]> = {};

        if (f.q.trim() !== '') data.q = f.q.trim();
        if (f.severity.length > 0) data.severity = f.severity;
        if (f.range) data.range = f.range;
        if (f.from) data.from = f.from;
        if (f.to) data.to = f.to;

        router.get(AuditLogController.index().url, data, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const type = (value: string) => {
        setSearch(value);

        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => go({ q: value }), 350);
    };

    const pick = (range: Range | null) => {
        if (range === null) return go({ range: null, from: null, to: null });
        if (range === 'custom') {
            return go({
                range,
                from: from ? startOf(from) : null,
                to: to ? endOf(to) : null,
            });
        }

        go({ range, from: presetFrom(range), to: null });
    };

    const custom = (nextFrom: string, nextTo: string) =>
        go({
            range: 'custom',
            from: nextFrom ? startOf(nextFrom) : null,
            to: nextTo ? endOf(nextTo) : null,
        });

    const toggle = (grade: Severity) =>
        go({
            severity: filters.severity.includes(grade)
                ? filters.severity.filter((s) => s !== grade)
                : [...filters.severity, grade],
        });

    const active =
        filters.q !== '' ||
        filters.severity.length > 0 ||
        filters.range !== null ||
        filters.from !== null ||
        filters.to !== null;

    const clear = () => {
        setSearch('');
        setFrom('');
        setTo('');
        go({ q: '', severity: [], range: null, from: null, to: null });
    };

    return (
        <Card className="mb-4">
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative w-full min-w-0 sm:max-w-md sm:flex-1">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 z-10 size-4 -translate-y-1/2 opacity-60"
                            aria-hidden
                        />
                        <input
                            type="text"
                            className="input w-full pl-9"
                            value={search}
                            onChange={(e) => type(e.target.value)}
                            placeholder="Search who, action or IP address"
                            aria-label="Search the audit log by person, action or IP address"
                            autoComplete="off"
                            spellCheck={false}
                        />
                    </div>
                    <p
                        className="text-sm text-base-content/70"
                        role="status"
                        aria-live="polite"
                    >
                        {total === 1
                            ? '1 entry'
                            : `${total.toLocaleString()} entries`}
                    </p>
                    {active && (
                        <button
                            type="button"
                            className="btn btn-text btn-sm"
                            onClick={clear}
                        >
                            <X className="size-4" aria-hidden />
                            Clear filters
                        </button>
                    )}
                </div>

                <div className="flex flex-wrap gap-x-8 gap-y-3">
                    <div
                        role="group"
                        aria-label="Time range"
                        className="space-y-1.5"
                    >
                        <p className="text-sm text-base-content/70">When</p>
                        <div className="flex flex-wrap gap-1.5">
                            {RANGES.map((r) => (
                                <button
                                    key={r.label}
                                    type="button"
                                    aria-pressed={filters.range === r.id}
                                    className={cn(
                                        'btn btn-sm',
                                        filters.range === r.id
                                            ? 'btn-primary'
                                            : 'btn-soft',
                                    )}
                                    onClick={() => pick(r.id)}
                                >
                                    {r.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div role="group" aria-label="Risk" className="space-y-1.5">
                        <p className="text-sm text-base-content/70">Risk</p>
                        <div className="flex flex-wrap gap-1.5">
                            {GRADES.map((g) => {
                                const on = filters.severity.includes(g.id);

                                return (
                                    <button
                                        key={g.id}
                                        type="button"
                                        aria-pressed={on}
                                        title={g.hint}
                                        className={cn(
                                            'btn btn-sm',
                                            on ? g.on : 'btn-soft',
                                        )}
                                        onClick={() => toggle(g.id)}
                                    >
                                        {g.icon && (
                                            <g.icon
                                                className="size-4"
                                                aria-hidden
                                            />
                                        )}
                                        {g.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {filters.range === 'custom' && (
                    <div className="flex flex-wrap items-end gap-3">
                        <div>
                            <label
                                className="mb-1 label-text block px-0!"
                                htmlFor="audit-from"
                            >
                                From
                            </label>
                            <input
                                id="audit-from"
                                type="date"
                                className="input"
                                value={from}
                                max={to || undefined}
                                onChange={(e) => {
                                    setFrom(e.target.value);
                                    custom(e.target.value, to);
                                }}
                            />
                        </div>
                        <div>
                            <label
                                className="mb-1 label-text block px-0!"
                                htmlFor="audit-to"
                            >
                                To
                            </label>
                            <input
                                id="audit-to"
                                type="date"
                                className="input"
                                value={to}
                                min={from || undefined}
                                onChange={(e) => {
                                    setTo(e.target.value);
                                    custom(from, e.target.value);
                                }}
                            />
                        </div>
                    </div>
                )}
            </div>
        </Card>
    );
}
