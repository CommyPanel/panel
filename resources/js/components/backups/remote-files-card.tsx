import { Download, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import BackupRemoteController from '@/actions/App/Http/Controllers/BackupRemoteController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import { formatBytes, formatDate, timeAgo } from '@/lib/format';
import { Trans } from '@/lib/trans';

type RemoteFile = {
    path: string;
    name: string;
    size: number;
    modified: number;
};
type Group = {
    kind: 'panel' | 'database' | 'volume';
    label: string | null;
    files: RemoteFile[];
};
type Listing = { groups: Group[]; truncated: boolean };

/**
 * What is on the off-server storage. Read only when Refresh is pressed, because it is a call
 * to another service. Files can be downloaded through the panel; nothing is restored from here.
 */
export function RemoteFilesCard({ manage }: { manage: boolean }) {
    const { t } = useT();
    const confirmed = useConfirmedAction();
    const [listing, setListing] = useState<Listing | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const refresh = async () => {
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(BackupRemoteController.files().url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const body = (await response
                .json()
                .catch(() => ({}))) as Listing & {
                error?: string;
            };

            if (!response.ok) {
                throw new Error(
                    body.error ??
                        t('The request failed (:status).', {
                            status: response.status,
                        }),
                );
            }

            setListing(body);
        } catch (e) {
            setError(e instanceof Error ? e.message : t('Could not load.'));
        } finally {
            setLoading(false);
        }
    };

    const download = (file: RemoteFile) =>
        void confirmed.run(() => {
            window.location.href = BackupRemoteController.download({
                query: { path: file.path },
            }).url;
        });

    const title = (group: Group) => {
        if (group.kind === 'panel') return t('Panel backups');
        if (group.kind === 'database')
            return t('Database dumps of :name', { name: group.label ?? '' });

        return t('Volume archives of :name', { name: group.label ?? '' });
    };

    return (
        <Card
            title={t('Copies on remote storage')}
            description={t(
                'What is on the storage now, read when you press Refresh. Download works through the panel with your password; nothing is restored from here.',
            )}
            actions={
                <Button
                    variant="soft"
                    size="sm"
                    loading={loading}
                    onClick={() => void refresh()}
                >
                    <RefreshCw className="size-4" aria-hidden />
                    {t('Refresh')}
                </Button>
            }
        >
            {error && <Alert variant="error">{error}</Alert>}
            {listing === null && !error && (
                <p className="text-sm text-base-content/70">
                    {t('Press Refresh to see what the storage holds.')}
                </p>
            )}
            {listing !== null && listing.groups.length === 0 && (
                <p className="text-sm text-base-content/70">
                    {t('There are no copies on the storage yet.')}
                </p>
            )}
            {listing?.groups.map((group) => (
                <div
                    key={`${group.kind}/${group.label ?? ''}`}
                    className="min-w-0"
                >
                    <h3 className="mb-1 text-sm font-medium">{title(group)}</h3>
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>{t('File')}</th>
                                    <th>{t('Made')}</th>
                                    <th className="text-right">{t('Size')}</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {group.files.map((file) => (
                                    <tr key={file.path}>
                                        <td className="break-all">
                                            <code className="text-xs">
                                                {file.name}
                                            </code>
                                        </td>
                                        <td
                                            className="whitespace-nowrap"
                                            title={formatDate(
                                                new Date(
                                                    file.modified * 1000,
                                                ).toISOString(),
                                            )}
                                        >
                                            {timeAgo(
                                                new Date(
                                                    file.modified * 1000,
                                                ).toISOString(),
                                            )}
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            {formatBytes(file.size)}
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            {manage && (
                                                <Button
                                                    variant="soft"
                                                    size="sm"
                                                    onClick={() =>
                                                        download(file)
                                                    }
                                                >
                                                    <Download
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                    {t('Download')}
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            ))}
            {listing?.truncated && (
                <p className="text-sm text-base-content/70">
                    {t('Only the newest 500 files are listed.')}
                </p>
            )}
            <div className="space-y-2 text-sm text-base-content/80">
                <p className="font-medium">
                    {t('If this server is lost, recover from these copies')}
                </p>
                <ol className="list-decimal space-y-1.5 pl-5">
                    <li>
                        {t(
                            'Install CommyPanel on a new server and download the files you need from the list above (or straight from your storage).',
                        )}
                    </li>
                    <li>
                        <Trans
                            text="Panel backup: put the <b>same</b> <code>APP_KEY</code> into <code2>/etc/commypanel/env</code2> (from your own copy; backups never hold it), then run <code3>sudo commypanel restore FILE</code3>."
                            components={{
                                b: <strong />,
                                code: <code />,
                                code2: <code />,
                                code3: <code />,
                            }}
                        />
                    </li>
                    <li>
                        {t(
                            'Database dump: create the database again, then import the file in phpMyAdmin or pgAdmin, or with the database’s own tools (mysql or psql). It is plain SQL, compressed with gzip.',
                        )}
                    </li>
                    <li>
                        <Trans
                            text="Volume archive: with the app stopped, unpack it into the volume's folder with <code>tar -xzpf FILE -C /var/lib/commypanel/volumes/APP/VOLUME</code> as root."
                            components={{ code: <code /> }}
                        />
                    </li>
                </ol>
            </div>
            {confirmed.modal}
        </Card>
    );
}
