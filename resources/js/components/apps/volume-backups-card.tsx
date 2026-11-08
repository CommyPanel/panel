import { router } from '@inertiajs/react';
import { Archive, Download, RotateCcw, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import AppVolumeController from '@/actions/App/Http/Controllers/AppVolumeController';
import AppVolumeBackupController from '@/actions/App/Http/Controllers/AppVolumeBackupController';
import { VolumeRestoreModal } from '@/components/apps/volume-restore-modal';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import { Trans } from '@/lib/trans';
import { formatBytes, formatDate, timeAgo } from '@/lib/format';

type Backup = { name: string; volume: string; size: number; created: number };
type Pending = Record<string, { running?: true; failed?: string }>;
type Restores = Record<
    string,
    { running?: true; failed?: string; done?: string }
>;
type Overview = {
    backups: Backup[];
    pending: Pending;
    restores: Restores;
    keep: number;
};

async function getBackups(
    url: string,
    failed: (status: number) => string,
): Promise<Overview> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) throw new Error(failed(response.status));

    return (await response.json()) as Overview;
}

/**
 * Archives of the app's volumes, kept on this server. A volume is backed up
 * when you ask (it can be large), as a gzipped tar of its files.
 */
export function VolumeBackupsCard({
    app,
    volumes,
    nightlyAt,
}: {
    app: string;
    volumes: { id: number; name: string; nightly: boolean }[];
    nightlyAt: string;
}) {
    const { t } = useT();
    const confirmed = useConfirmedAction();
    const [overview, setOverview] = useState<Overview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [restoring, setRestoring] = useState<Backup | null>(null);
    const [restoreError, setRestoreError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            setOverview(
                await getBackups(
                    AppVolumeBackupController.index(app).url,
                    (status) => t('The request failed (:status).', { status }),
                ),
            );
            setError(null);
        } catch (e) {
            setError(e instanceof Error ? e.message : t('Could not load.'));
        }
    }, [app, t]);

    useEffect(() => {
        void load();
    }, [load]);

    // While one is being made, look again every few seconds.
    const running =
        Object.values(overview?.pending ?? {}).some((p) => p.running) ||
        Object.values(overview?.restores ?? {}).some((p) => p.running);
    useEffect(() => {
        if (!running) return;

        const timer = setInterval(() => void load(), 3000);

        return () => clearInterval(timer);
    }, [running, load]);

    const setNightly = (id: number, nightly: boolean) =>
        router.put(
            AppVolumeController.nightly({ app, volume: id }),
            { nightly },
            { preserveScroll: true },
        );

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

    const restore = () => {
        if (!restoring) return;

        void confirmed.run(() =>
            router.post(
                AppVolumeBackupController.restore({
                    app,
                    file: restoring.name,
                }),
                { confirm: restoring.volume },
                {
                    preserveScroll: true,
                    onStart: () => {
                        setBusy(true);
                        setRestoreError(undefined);
                    },
                    onSuccess: () => {
                        setRestoring(null);
                        void load();
                    },
                    onError: (errors) => setRestoreError(errors.volume),
                    onFinish: () => setBusy(false),
                },
            ),
        );
    };

    const remove = (file: Backup) =>
        void confirmed.run(() => {
            if (
                !window.confirm(
                    t('Delete the backup :name?', { name: file.name }),
                )
            )
                return;

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
        <>
            <Card
                title={t('Backups')}
                description={t(
                    'A volume is saved as one compressed archive on this server. The newest :keep of each are kept.',
                    { keep: overview.keep },
                )}
                flush={overview.backups.length > 0}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {volumes.map(({ name }) => (
                            <Button
                                key={name}
                                variant="soft"
                                size="sm"
                                disabled={overview.pending[name]?.running}
                                onClick={() => backUp(name)}
                            >
                                <Archive className="size-4" aria-hidden />
                                {overview.pending[name]?.running
                                    ? t('Backing up :name…', { name })
                                    : t('Back up :name', { name })}
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
                <div className="space-y-2 px-6 pb-3">
                    {volumes.map((v) => (
                        <label
                            key={v.id}
                            className="flex cursor-pointer items-start gap-3"
                        >
                            <input
                                type="checkbox"
                                role="switch"
                                className="switch mt-0.5 switch-primary"
                                checked={v.nightly}
                                onChange={(e) =>
                                    setNightly(v.id, e.target.checked)
                                }
                            />
                            <span className="text-sm">
                                <span className="font-medium">
                                    {t('Archive :name every night', {
                                        name: v.name,
                                    })}
                                </span>
                                <span className="block text-base-content/70">
                                    {t(
                                        'Archives the whole folder every night at :time; big volumes use disk, the newest :keep are kept.',
                                        {
                                            time: nightlyAt,
                                            keep: overview.keep,
                                        },
                                    )}
                                </span>
                            </span>
                        </label>
                    ))}
                </div>
                {Object.entries(overview.restores).map(([name, r]) =>
                    r.running ? (
                        <Alert key={name} variant="info">
                            {t(
                                'Restoring :name. The app is stopped while the files are put back.',
                                { name },
                            )}
                        </Alert>
                    ) : r.failed ? (
                        <Alert key={name} variant="error">
                            {r.failed}
                        </Alert>
                    ) : r.done ? (
                        <Alert key={name} variant="success">
                            {r.done}
                        </Alert>
                    ) : null,
                )}
                {overview.backups.length === 0 ? (
                    <p className="text-sm text-base-content/70">
                        {t(
                            'No backup yet. Press a Back up button above; a large volume takes a while.',
                        )}
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>{t('Volume')}</th>
                                    <th>{t('Made')}</th>
                                    <th className="text-right">{t('Size')}</th>
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
                                                {t('Download')}
                                            </Button>
                                            <Button
                                                variant="soft"
                                                size="sm"
                                                disabled={
                                                    overview.restores[
                                                        file.volume
                                                    ]?.running
                                                }
                                                onClick={() => {
                                                    setRestoreError(undefined);
                                                    setRestoring(file);
                                                }}
                                            >
                                                <RotateCcw
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                                {t('Restore')}
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
                                                {t('Delete')}
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <p className="px-6 pb-4 text-sm text-base-content/70">
                    <Trans
                        text="An archive holds every file of the volume, so downloading one needs your password and is recorded. Links, pipes and other special files are left out. It stays on this server: copy it somewhere else now and then. To put one back, press <b>Restore</b>: the app stops for a moment and the volume's files are replaced, after a safety archive. Archives made before CommyPanel 0.8.0 cannot be restored from here; unpack one by hand with <code>tar -xzpf FILE -C /var/lib/commypanel/volumes/:app/NAME</code> as root while the app is stopped."
                        components={{ code: <code />, b: <strong /> }}
                        values={{ app }}
                    />
                </p>
            </Card>
            <VolumeRestoreModal
                file={restoring?.name ?? null}
                volume={restoring?.volume ?? ''}
                busy={busy}
                error={restoreError}
                onSubmit={restore}
                onClose={() => setRestoring(null)}
            />
            {confirmed.modal}
        </>
    );
}
