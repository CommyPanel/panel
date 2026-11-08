import { Link, router } from '@inertiajs/react';
import { RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { StatusBadge } from '@/components/ui/status-badge';
import { useT } from '@/hooks/use-t';
import { formatDate } from '@/lib/format';
import { Trans } from '@/lib/trans';
import type { DomainRow, ServerAddress } from '@/types';

type Props = {
    domain: DomainRow;
    server: ServerAddress;
    /** Link to the app the domain belongs to (the global overview). */
    showApp?: boolean;
};

export function RecordsTable({
    domain,
    server,
}: {
    domain: string;
    server: ServerAddress;
}) {
    const { t } = useT();
    const records = [
        server.ipv4 && { type: 'A', value: server.ipv4 },
        server.ipv6 && { type: 'AAAA', value: server.ipv6 },
    ].filter((record) => record !== null && record !== '') as {
        type: string;
        value: string;
    }[];

    if (records.length === 0) return null;

    return (
        <div className="space-y-2">
            <p className="text-sm font-medium">
                {t('Create these records at your DNS provider')}
            </p>
            <div className="overflow-x-auto rounded-field border border-base-300">
                <table className="table">
                    <thead>
                        <tr>
                            <th>{t('Type')}</th>
                            <th>{t('Name')}</th>
                            <th>{t('Value')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {records.map((record) => (
                            <tr key={record.type}>
                                <td>
                                    <code>{record.type}</code>
                                </td>
                                <td className="break-all">
                                    <code>{domain}</code>
                                </td>
                                <td>
                                    <code>{record.value}</code>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="text-xs text-base-content/70">
                {t(
                    'Some providers want only the part before your main domain as the name (for example app instead of app.example.com).',
                )}
            </p>
        </div>
    );
}

export function DomainCard({ domain, server, showApp = false }: Props) {
    const { t } = useT();
    const [checking, setChecking] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const check = () =>
        router.post(
            DomainController.check(domain.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
            },
        );

    const remove = () =>
        router.delete(DomainController.destroy(domain.id), {
            preserveScroll: true,
            onStart: () => setDeleting(true),
            onFinish: () => {
                setDeleting(false);
                setConfirmDelete(false);
            },
        });

    return (
        <Card
            title={domain.name}
            actions={
                <div className="flex items-center gap-2">
                    <StatusBadge status={domain.status} />
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label={t('Delete :domain', {
                            domain: domain.name,
                        })}
                        onClick={() => setConfirmDelete(true)}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            }
        >
            {(showApp || domain.redirect_to) && (
                <p className="text-sm text-base-content/70">
                    {showApp && domain.app && (
                        <>
                            <Trans
                                text="Serves <link>:app</link>"
                                components={{
                                    link: (
                                        <Link
                                            href={AppController.show(
                                                domain.app,
                                            )}
                                            className="link link-primary"
                                        />
                                    ),
                                }}
                                values={{ app: domain.app_name ?? domain.app }}
                            />
                        </>
                    )}
                    {showApp && domain.app && domain.redirect_to && '. '}
                    {domain.redirect_to && (
                        <>
                            <Trans
                                text="Redirects to <code>:target</code>"
                                components={{ code: <code /> }}
                                values={{ target: domain.redirect_to }}
                            />
                        </>
                    )}
                </p>
            )}

            {domain.problems.length > 0 && (
                <Alert
                    variant={domain.status === 'proxied' ? 'info' : 'warning'}
                >
                    <ul className="space-y-1">
                        {domain.problems.map((problem) => (
                            <li key={problem}>{problem}</li>
                        ))}
                    </ul>
                </Alert>
            )}

            {domain.status !== 'verified' && (
                <RecordsTable domain={domain.name} server={server} />
            )}

            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-base-content/70">
                    {domain.last_checked_at
                        ? t('Last checked :date', {
                              date: formatDate(domain.last_checked_at),
                          })
                        : t('Not checked yet')}
                </p>
                <Button
                    variant="soft"
                    size="sm"
                    onClick={check}
                    loading={checking}
                >
                    <RefreshCw className="size-4" aria-hidden />
                    {t('Check DNS')}
                </Button>
            </div>

            <ConfirmModal
                open={confirmDelete}
                title={t('Remove :domain?', { domain: domain.name })}
                confirmLabel={t('Remove domain')}
                loading={deleting}
                onConfirm={remove}
                onClose={() => setConfirmDelete(false)}
            >
                {t(
                    'The domain stops serving its app. Your DNS records are not touched.',
                )}
            </ConfirmModal>
        </Card>
    );
}
