import { router, usePage } from '@inertiajs/react';
import { DatabaseBackup, Download, Trash2 } from 'lucide-react';
import { useState } from 'react';
import BackupController from '@/actions/App/Http/Controllers/BackupController';
import { RemoteBackupCard } from '@/components/backups/remote-backup-card';
import { RemoteFilesCard } from '@/components/backups/remote-files-card';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { useT } from '@/hooks/use-t';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import AppLayout from '@/layouts/app-layout';
import { Trans } from '@/lib/trans';
import { formatBytes, formatDate, timeAgo } from '@/lib/format';
import { useCan } from '@/lib/permissions';
import type { PanelBackup, RemoteBackupSettings } from '@/types';

type Props = {
    backups: PanelBackup[];
    keep: number;
    folder: string;
    remote: RemoteBackupSettings;
};

export default function Backups({ backups, keep, folder, remote }: Props) {
    const { t } = useT();
    const can = useCan();
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();
    const [busy, setBusy] = useState(false);
    const [deleting, setDeleting] = useState<PanelBackup | null>(null);
    const manage = can('backups.manage');

    const backUp = () =>
        router.post(
            BackupController.store(),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    const download = (backup: PanelBackup) =>
        void confirmed.run(() => {
            window.location.href = BackupController.download(backup.name).url;
        });

    const remove = () => {
        if (!deleting) return;

        void confirmed.run(() =>
            router.delete(BackupController.destroy(deleting.name), {
                preserveScroll: true,
                onFinish: () => setDeleting(null),
            }),
        );
    };

    return (
        <AppLayout title={t('Backups')}>
            <PageHeader
                title={t('Backups')}
                description={t(
                    "Copies of the panel's own data, so a damaged or lost database can be brought back.",
                )}
                actions={
                    manage && (
                        <Button onClick={backUp} loading={busy}>
                            <DatabaseBackup className="size-4" aria-hidden />
                            {t('Back up now')}
                        </Button>
                    )
                }
            />

            {errors.backup && <Alert variant="error">{errors.backup}</Alert>}
            {errors.remote && <Alert variant="error">{errors.remote}</Alert>}

            <Card
                title={t('Panel backups')}
                description={t(
                    'A backup is made every night at 03:30, and the newest :keep are kept. They are stored on this server in :folder.',
                    { keep, folder },
                )}
                flush={backups.length > 0}
            >
                {backups.length === 0 ? (
                    <p className="text-sm text-base-content/70">
                        {t(
                            'There is no backup yet. The first one is made tonight, or press Back up now.',
                        )}
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>{t('Made')}</th>
                                    <th className="text-right">{t('Size')}</th>
                                    <th>{t('File')}</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {backups.map((backup) => (
                                    <tr key={backup.name}>
                                        <td
                                            className="whitespace-nowrap"
                                            title={formatDate(
                                                backup.created_at,
                                            )}
                                        >
                                            {timeAgo(backup.created_at)}
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            {formatBytes(backup.size)}
                                        </td>
                                        <td className="break-all">
                                            <code className="text-xs">
                                                {backup.name}
                                            </code>
                                            {backup.name.includes(
                                                '-before',
                                            ) && (
                                                <span className="badge-soft badge-neutral ml-2 badge badge-sm">
                                                    {t('before a restore')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            {manage && (
                                                <>
                                                    <Button
                                                        variant="soft"
                                                        size="sm"
                                                        onClick={() =>
                                                            download(backup)
                                                        }
                                                    >
                                                        <Download
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        {t('Download')}
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setDeleting(backup)
                                                        }
                                                    >
                                                        <Trash2
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        {t('Delete')}
                                                    </Button>
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <RemoteBackupCard remote={remote} manage={manage} />

            {remote.enabled && <RemoteFilesCard manage={manage} />}

            <Card title={t('What a backup holds, and what it does not')}>
                <ul className="list-disc space-y-2 pl-5 text-sm text-base-content/80">
                    <li>
                        <Trans
                            text="<b>It holds</b> the panel's database: apps and their settings, domains, users and roles, the audit log, database servers and databases. Secrets in it (environment variables, tokens, database passwords) are encrypted."
                            components={{ b: <strong /> }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>It does not hold the encryption key.</b> Without <code>APP_KEY</code> (in <code2>/etc/commypanel/env</code2>) those encrypted values cannot be read after a restore. Keep a copy of the key somewhere safe, apart from the backups."
                            components={{
                                b: <strong />,
                                code: <code />,
                                code2: <code />,
                            }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>It does not hold files.</b> The files in apps' volumes, the data of database servers and built images are not part of it."
                            components={{ b: <strong /> }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>Copy backups off this server.</b> A backup on the same disk is lost with it. Download one now and then, or copy the folder above with your own tools."
                            components={{ b: <strong /> }}
                        />
                    </li>
                    <li>
                        {t(
                            "Downloading asks for your password and is recorded in the audit log, because a backup contains everything in the panel's database.",
                        )}
                    </li>
                </ul>
            </Card>

            <Card
                title={t('Restoring')}
                description={t(
                    "Restoring replaces the panel's database, so it is done on the server, not in this page.",
                )}
            >
                <ol className="list-decimal space-y-1.5 pl-5 text-sm text-base-content/80">
                    <li>
                        <Trans
                            text="Connect to the server over SSH. To restore onto a new server, install CommyPanel there first and put the old <code>APP_KEY</code> into its <code2>/etc/commypanel/env</code2>."
                            components={{ code: <code />, code2: <code /> }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="Run <code>sudo commypanel restore FILE</code>, with the name of a backup above or the path to a file you downloaded."
                            components={{ code: <code /> }}
                        />
                    </li>
                    <li>
                        {t(
                            'The panel stops for a moment, the database is replaced, and the panel starts again. The database as it was before is kept as a backup marked “before a restore”.',
                        )}
                    </li>
                </ol>
            </Card>

            <ConfirmModal
                open={deleting !== null}
                title={t('Delete this backup?')}
                confirmLabel={t('Delete backup')}
                onConfirm={remove}
                onClose={() => setDeleting(null)}
            >
                <Trans
                    text="<code>:name</code> is deleted. This cannot be undone."
                    components={{ code: <code className="break-all" /> }}
                    values={{ name: deleting?.name ?? '' }}
                />
            </ConfirmModal>
            {confirmed.modal}
        </AppLayout>
    );
}
