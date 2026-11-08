import { Link } from '@inertiajs/react';
import { OctagonAlert, TriangleAlert } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { AuditFilterBar } from '@/components/audit/audit-filters';
import type { AuditFilters, Severity } from '@/components/audit/audit-filters';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/format';

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
    const { t } = useT();

    return (
        <AppLayout title={t('Audit log')}>
            <PageHeader
                title={t('Audit log')}
                description={t(
                    'Security-relevant actions on this panel, newest first.',
                )}
            />
            <ul
                className="mb-4 flex flex-wrap gap-x-6 gap-y-2 text-sm"
                aria-label={t('What the colours mean')}
            >
                <li className="flex items-center gap-2">
                    <span className="badge-soft badge badge-error">
                        <OctagonAlert className="size-3.5" aria-hidden />
                        {t('High risk')}
                    </span>
                    <span className="text-base-content/70">
                        {t('exposes something or destroys data or access')}
                    </span>
                </li>
                <li className="flex items-center gap-2">
                    <span className="badge-soft badge badge-warning">
                        <TriangleAlert className="size-3.5" aria-hidden />
                        {t('Sensitive')}
                    </span>
                    <span className="text-base-content/70">
                        {t(
                            'secrets seen, access or settings changed, a failure',
                        )}
                    </span>
                </li>
                <li className="flex items-center gap-2">
                    <span className="badge-soft badge-neutral badge">
                        {t('Routine')}
                    </span>
                    <span className="text-base-content/70">
                        {t('everything else')}
                    </span>
                </li>
            </ul>
            <AuditFilterBar filters={filters} total={logs.total} />
            <Card flush>
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>{t('When')}</th>
                                <th>{t('Who')}</th>
                                <th>{t('Action')}</th>
                                <th>{t('IP')}</th>
                                <th>{t('Details')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map((log) => (
                                <tr key={log.id} className={ROW[log.severity]}>
                                    <td
                                        className={`whitespace-nowrap ${EDGE[log.severity]}`}
                                    >
                                        {formatDate(log.created_at)}
                                    </td>
                                    <td>{log.user ?? '-'}</td>
                                    <td>
                                        <span className="flex items-center gap-1.5">
                                            {log.severity === 'danger' && (
                                                <OctagonAlert
                                                    className="size-4 shrink-0 text-error"
                                                    aria-label={t('High risk')}
                                                />
                                            )}
                                            {log.severity === 'warning' && (
                                                <TriangleAlert
                                                    className="size-4 shrink-0 text-warning"
                                                    aria-label={t('Sensitive')}
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
                                            ? t(
                                                  'No entries match these filters.',
                                              )
                                            : t('Nothing recorded yet.')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <nav
                    className="flex flex-wrap gap-1 border-t border-base-300 p-3"
                    aria-label={t('Audit log pages')}
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
