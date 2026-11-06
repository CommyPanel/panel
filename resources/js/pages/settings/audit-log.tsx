import { Link } from '@inertiajs/react';
import { OctagonAlert, TriangleAlert } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { AuditFilterBar } from '@/components/audit/audit-filters';
import type { AuditFilters, Severity } from '@/components/audit/audit-filters';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

const ROW: Record<Severity, string> = {
    danger: 'bg-error/10 hover:bg-error/15',
    warning: 'bg-warning/10 hover:bg-warning/15',
    info: '',
};

const EDGE: Record<Severity, string> = {
    danger: 'border-l-4 border-error',
    warning: 'border-l-4 border-warning',
    info: 'border-l-4 border-transparent',
};

type Entry = {
    id: number;
    action: string;
    severity: Severity;
    user: string | null;
    ip: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
};

type PageLink = { url: string | null; label: string; active: boolean };

type Props = {
    logs: { data: Entry[]; links: PageLink[]; total: number };
    filters: AuditFilters;
};

export default function AuditLog({ logs, filters }: Props) {
    return (
        <AppLayout title="Audit log">
            <PageHeader
                title="Audit log"
                description="Security-relevant actions on this panel, newest first."
            />
            <ul
                className="mb-4 flex flex-wrap gap-x-6 gap-y-2 text-sm"
                aria-label="What the colours mean"
            >
                <li className="flex items-center gap-2">
                    <span className="badge-soft badge badge-error">
                        <OctagonAlert className="size-3.5" aria-hidden />
                        High risk
                    </span>
                    <span className="text-base-content/70">
                        exposes something or destroys data or access
                    </span>
                </li>
                <li className="flex items-center gap-2">
                    <span className="badge-soft badge badge-warning">
                        <TriangleAlert className="size-3.5" aria-hidden />
                        Sensitive
                    </span>
                    <span className="text-base-content/70">
                        secrets seen, access or settings changed, a failure
                    </span>
                </li>
                <li className="flex items-center gap-2">
                    <span className="badge-soft badge-neutral badge">
                        Routine
                    </span>
                    <span className="text-base-content/70">
                        everything else
                    </span>
                </li>
            </ul>
            <AuditFilterBar filters={filters} total={logs.total} />
            <Card flush>
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Who</th>
                                <th>Action</th>
                                <th>IP</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map((log) => (
                                <tr key={log.id} className={ROW[log.severity]}>
                                    <td
                                        className={`whitespace-nowrap ${EDGE[log.severity]}`}
                                    >
                                        {new Date(
                                            log.created_at,
                                        ).toLocaleString()}
                                    </td>
                                    <td>{log.user ?? '-'}</td>
                                    <td>
                                        <span className="flex items-center gap-1.5">
                                            {log.severity === 'danger' && (
                                                <OctagonAlert
                                                    className="size-4 shrink-0 text-error"
                                                    aria-label="High risk"
                                                />
                                            )}
                                            {log.severity === 'warning' && (
                                                <TriangleAlert
                                                    className="size-4 shrink-0 text-warning"
                                                    aria-label="Sensitive"
                                                />
                                            )}
                                            <code>{log.action}</code>
                                        </span>
                                    </td>
                                    <td>{log.ip ?? '-'}</td>
                                    <td className="text-sm text-base-content/70">
                                        {log.metadata
                                            ? JSON.stringify(log.metadata)
                                            : ''}
                                    </td>
                                </tr>
                            ))}
                            {logs.data.length === 0 && (
                                <tr>
                                    <td colSpan={5}>
                                        {logs.total === 0 &&
                                        (filters.q !== '' ||
                                            filters.severity.length > 0 ||
                                            filters.range !== null)
                                            ? 'No entries match these filters.'
                                            : 'Nothing recorded yet.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav
                    className="flex flex-wrap gap-1 border-t border-base-300 p-3"
                    aria-label="Audit log pages"
                >
                    {logs.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
                                className={
                                    link.active
                                        ? 'btn btn-sm btn-primary'
                                        : 'btn btn-text btn-sm'
                                }
                                dangerouslySetInnerHTML={{
                                    __html: link.label,
                                }}
                            />
                        ) : null,
                    )}
                </nav>
            </Card>
        </AppLayout>
    );
}
