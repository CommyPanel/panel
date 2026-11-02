import { router } from '@inertiajs/react';
import { Archive, Download, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import AppVolumeBackupController from '@/actions/App/Http/Controllers/AppVolumeBackupController';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { formatBytes, formatDate, timeAgo } from '@/lib/format';

type Backup = { name: string; volume: string; size: number; created: number };
type Pending = Record<string, { running?: true; failed?: string }>;
type Overview = { backups: Backup[]; pending: Pending; keep: number };

async function getBackups(url: string): Promise<Overview> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok)
        throw new Error(`The request failed (${response.status}).`);

    return (await response.json()) as Overview;
}

/**
 * Archives of the app's volumes, kept on this server. A volume is backed up
 * when you ask (it can be large), as a gzipped tar of its files.
 */
export function VolumeBackupsCard({
    app,
    volumes,
}: {
    app: string;
    volumes: string[];
}) {
    const confirmed = useConfirmedAction();
    const [overview, setOverview] = useState<Overview | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(async () => {
        try {
            setOverview(
                await getBackups(AppVolumeBackupController.index(app).url),
            );
            setError(null);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Could not load.');
        }
    }, [app]);

    useEffect(() => {
        void load();
    }, [load]);

    // While one is being made, look again every few seconds.
    const running = Object.values(overview?.pending ?? {}).some(
        (p) => p.running,
    );
    useEffect(() => {
        if (!running) return;

        const timer = setInterval(() => void load(), 3000);

        return () => clearInterval(timer);
    }, [running, load]);

    const backUp = (name: string) =>
        router.post(
            AppVolumeBackupController.store({ app, name }),
            {},
            { preserveScroll: true, onSuccess: () => void load() },
        );

    const download = (file: Backup) =>
        void confirmed.run(() => {
            window.location.href = AppVolumeBackupController.download({
                app,
                file: file.name,
            }).url;
        });

    const remove = (file: Backup) =>
        void confirmed.run(() => {
            if (!window.confirm(`Delete the backup ${file.name}?`)) return;

            router.delete(
                AppVolumeBackupController.destroy({ app, file: file.name }),
                { preserveScroll: true, onSuccess: () => void load() },
            );
        });

    if (overview === null) {
        return error ? (
            <p className="text-sm text-error" role="alert">
                {error}
            </p>
        ) : null;
    }

    const failures = Object.entries(overview.pending).filter(
        ([, p]) => p.failed,
    );

    return (
        <Card
            title="Backups"
            description={`A volume is saved as one compressed archive on this server. The newest ${overview.keep} of each are kept.`}
            flush={overview.backups.length > 0}
            actions={
                <div className="flex flex-wrap gap-2">
                    {volumes.map((name) => (
                        <Button
                            key={name}
                            variant="soft"
                            size="sm"
                            disabled={overview.pending[name]?.running}
                            onClick={() => backUp(name)}
                        >
                            <Archive className="size-4" aria-hidden />
                            {overview.pending[name]?.running
                                ? `Backing up ${name}…`
                                : `Back up ${name}`}
                        </Button>
                    ))}
                </div>
            }
        >
            {failures.map(([name, p]) => (
                <p
                    key={name}
                    className="px-6 py-2 text-sm text-error"
                    role="alert"
                >
                    {p.failed}
                </p>
            ))}
            {overview.backups.length === 0 ? (
                <p className="text-sm text-base-content/70">
                    No backup yet. Press a Back up button above; a large volume
                    takes a while.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Volume</th>
                                <th>Made</th>
                                <th className="text-right">Size</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {overview.backups.map((file) => (
                                <tr key={file.name}>
                                    <td>
                                        <code>{file.volume}</code>
                                    </td>
                                    <td
                                        className="whitespace-nowrap"
                                        title={formatDate(
                                            new Date(
                                                file.created * 1000,
                                            ).toISOString(),
                                        )}
                                    >
                                        {timeAgo(
                                            new Date(
                                                file.created * 1000,
                                            ).toISOString(),
                                        )}
                                    </td>
                                    <td className="text-right whitespace-nowrap">
                                        {formatBytes(file.size)}
                                    </td>
                                    <td className="text-right whitespace-nowrap">
                                        <Button
                                            variant="soft"
                                            size="sm"
                                            onClick={() => download(file)}
                                        >
                                            <Download
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Download
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(file)}
                                        >
                                            <Trash2
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Delete
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <p className="px-6 pb-4 text-sm text-base-content/70">
                An archive holds every file of the volume, so downloading one
                needs your password and is recorded. Links, pipes and other
                special files are left out. It stays on this server: copy it
                somewhere else now and then. To put one back, stop the app, then
                on the server run{' '}
                <code>
                    tar -xzpf FILE -C /var/lib/commypanel/volumes/{app}/NAME
                </code>{' '}
                as root.
            </p>
        </Card>
    );
}
