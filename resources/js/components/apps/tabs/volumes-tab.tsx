import { Link, router, usePage } from '@inertiajs/react';
import {
    FolderOpen,
    HardDrive,
    Info,
    Lock,
    LockOpen,
    Plus,
    RefreshCw,
    Search,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import AppLockController from '@/actions/App/Http/Controllers/AppLockController';
import AppVolumeFilesController from '@/actions/App/Http/Controllers/AppVolumeFilesController';
import {
    AboutVolumesModal,
    AddVolumeModal,
    RemoveVolumeModal,
} from '@/components/apps/volume-dialogs';
import { VolumeBackupsCard } from '@/components/apps/volume-backups-card';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { LockIcon } from '@/components/ui/lock-icon';
import { Tooltip } from '@/components/ui/tooltip';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import { formatBytes } from '@/lib/format';
import type { Params } from '@/lib/i18n';
import { Trans } from '@/lib/trans';
import type { AppSettings, AppVolume } from '@/types';

type VolumeRow = {
    id: number | null;
    name: string;
    path: string | null;
    attached: boolean;
    locked: boolean;
    exists: boolean;
    bytes: number;
    files: number;
    partial: boolean;
};
type Overview = { volumes: VolumeRow[]; total_bytes: number; running: boolean };

async function getOverview(
    url: string,
    t: (key: string, params?: Params) => string,
): Promise<Overview> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const body = (await response.json().catch(() => ({}))) as Overview & {
        error?: string;
    };

    if (!response.ok) {
        throw new Error(
            body.error ??
                t('The request failed (:status).', { status: response.status }),
        );
    }

    return body;
}

/**
 * The app's persistent volumes: how much space each uses, where they are
 * mounted, and what you can do with them. Browsing a volume opens its own page.
 * Only mounted while the tab is open.
 */
export function VolumesTab({ app }: { app: AppSettings }) {
    const { t, tn } = useT();
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();
    const [overview, setOverview] = useState<Overview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [query, setQuery] = useState('');
    const [adding, setAdding] = useState(false);
    const [about, setAbout] = useState(false);
    const [removing, setRemoving] = useState<AppVolume | null>(null);

    const load = useCallback(async () => {
        try {
            setOverview(
                await getOverview(
                    AppVolumeFilesController.index(app.slug).url,
                    t,
                ),
            );
            setError(null);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : t('The volumes could not be loaded.'),
            );
        }
    }, [app.slug, t]);

    // Every Inertia response brings a new volumes list: reload the sizes with it.
    useEffect(() => {
        void load();
    }, [load, app.volumes]);

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        return (overview?.volumes ?? []).filter(
            (row) =>
                q === '' ||
                row.name.includes(q) ||
                (row.path ?? '').toLowerCase().includes(q),
        );
    }, [overview, query]);

    const setLocked = (row: VolumeRow, locked: boolean) => {
        if (row.id === null) return;

        router.put(
            AppLockController.volume({ app: app.slug, volume: row.id }),
            { locked },
            { preserveScroll: true },
        );
    };

    const deleteData = (row: VolumeRow) =>
        void confirmed.run(() => {
            if (
                !window.confirm(
                    t('Delete all files in :name? This cannot be undone.', {
                        name: row.name,
                    }),
                )
            ) {
                return;
            }

            router.delete(
                AppVolumeFilesController.destroyData({
                    app: app.slug,
                    name: row.name,
                }),
                { preserveScroll: true, onSuccess: () => void load() },
            );
        });

    if (error) return <Alert variant="error">{error}</Alert>;

    if (overview === null) {
        return (
            <p className="text-sm text-base-content/70" role="status">
                {t('Loading...')}
            </p>
        );
    }

    const count = overview.volumes.length;

    return (
        <div className="space-y-6">
            {errors.volume && <Alert variant="error">{errors.volume}</Alert>}

            <Card
                title={t('Volumes')}
                description={
                    count === 0
                        ? t('Folders the app keeps between deployments.')
                        : tn(
                              'one::count volume using :size on this server.|other::count volumes using :size on this server.',
                              count,
                              { size: formatBytes(overview.total_bytes) },
                          )
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setAbout(true)}
                        >
                            <Info className="size-4" aria-hidden />
                            {t('How volumes work')}
                        </Button>
                        <Button
                            variant="soft"
                            size="sm"
                            onClick={() => void load()}
                            aria-label={t('Refresh the sizes')}
                        >
                            <RefreshCw className="size-4" aria-hidden />
                            {t('Refresh')}
                        </Button>
                        <Button size="sm" onClick={() => setAdding(true)}>
                            <Plus className="size-4" aria-hidden />
                            {t('Add volume')}
                        </Button>
                    </div>
                }
            >
                {count === 0 ? (
                    <div className="space-y-2 text-sm text-base-content/70">
                        <p>
                            {t(
                                'This app has no persistent volumes. Anything it writes inside its container is lost when the next deployment replaces the container.',
                            )}
                        </p>
                        <p>
                            {t(
                                'Add a volume for the folder your app writes files to, such as uploads or a database.',
                            )}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="relative w-full">
                            <label
                                htmlFor="volume-search"
                                className="mb-1 label-text block px-0!"
                            >
                                {t('Search volumes')}
                            </label>
                            <Search
                                className="pointer-events-none absolute bottom-3 left-3 size-4 text-base-content/60"
                                aria-hidden
                            />
                            <input
                                id="volume-search"
                                type="search"
                                className="input w-full pl-9"
                                placeholder={t('Search by path or folder name')}
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                autoComplete="off"
                                spellCheck={false}
                            />
                        </div>

                        {rows.length === 0 ? (
                            <p className="text-sm text-base-content/70">
                                {t('No volume matches “:query”.', { query })}
                            </p>
                        ) : (
                            <VolumeList
                                rows={rows}
                                slug={app.slug}
                                largest={Math.max(
                                    1,
                                    ...overview.volumes.map((v) => v.bytes),
                                )}
                                onLock={setLocked}
                                onRemove={(row) =>
                                    row.id !== null &&
                                    row.path !== null &&
                                    setRemoving({
                                        id: row.id,
                                        name: row.name,
                                        path: row.path,
                                        locked: row.locked,
                                    })
                                }
                                onDeleteData={deleteData}
                            />
                        )}
                        {overview.volumes.some((v) => !v.attached) && (
                            <p className="text-sm text-base-content/70">
                                {t(
                                    'A volume that is not attached is no longer mounted from new deployments. Its files stay until you delete them here, which only works once no container has it mounted: deploy the app again first.',
                                )}
                            </p>
                        )}
                    </>
                )}
            </Card>

            {overview.volumes.some((v) => v.attached && v.exists) && (
                <VolumeBackupsCard
                    app={app.slug}
                    volumes={overview.volumes
                        .filter((v) => v.attached && v.exists)
                        .map((v) => v.name)}
                />
            )}

            <AddVolumeModal
                slug={app.slug}
                open={adding}
                onClose={() => setAdding(false)}
            />
            <RemoveVolumeModal
                slug={app.slug}
                volume={removing}
                onClose={() => setRemoving(null)}
            />
            <AboutVolumesModal open={about} onClose={() => setAbout(false)} />
            {confirmed.modal}
        </div>
    );
}

function VolumeList({
    rows,
    slug,
    largest,
    onLock,
    onRemove,
    onDeleteData,
}: {
    rows: VolumeRow[];
    slug: string;
    largest: number;
    onLock: (row: VolumeRow, locked: boolean) => void;
    onRemove: (row: VolumeRow) => void;
    onDeleteData: (row: VolumeRow) => void;
}) {
    const { t, tn } = useT();

    return (
        <ul className="divide-y divide-base-300 rounded-field border border-base-300">
            {rows.map((row) => (
                <li key={row.name} className="space-y-2 p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                            <HardDrive
                                className="size-4 shrink-0 text-base-content/70"
                                aria-hidden
                            />
                            <code className="truncate text-sm">
                                {row.path ?? row.name}
                            </code>
                            {row.locked && <LockIcon what="volume" />}
                            {!row.attached && (
                                <span className="badge-soft badge badge-sm badge-warning">
                                    {t('Not attached')}
                                </span>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-1">
                            {row.exists && (
                                <Link
                                    href={AppVolumeFilesController.show({
                                        app: slug,
                                        name: row.name,
                                    })}
                                    className="btn btn-soft btn-sm"
                                >
                                    <FolderOpen
                                        className="size-4"
                                        aria-hidden
                                    />
                                    {t('Browse')}
                                </Link>
                            )}
                            {row.attached && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onLock(row, !row.locked)}
                                >
                                    {row.locked ? (
                                        <LockOpen
                                            className="size-4"
                                            aria-hidden
                                        />
                                    ) : (
                                        <Lock className="size-4" aria-hidden />
                                    )}
                                    {row.locked ? t('Unlock') : t('Lock')}
                                </Button>
                            )}
                            {row.attached &&
                                (row.locked ? (
                                    <Tooltip
                                        side="top"
                                        label={t(
                                            'Volume is locked and cannot be removed. Unlock it first.',
                                        )}
                                    >
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-disabled="true"
                                            className="cursor-not-allowed opacity-50"
                                            onClick={(e) => e.preventDefault()}
                                        >
                                            <Trash2
                                                className="size-4"
                                                aria-hidden
                                            />
                                            {t('Remove')}
                                        </Button>
                                    </Tooltip>
                                ) : (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => onRemove(row)}
                                    >
                                        <Trash2
                                            className="size-4"
                                            aria-hidden
                                        />
                                        {t('Remove')}
                                    </Button>
                                ))}
                            {!row.attached && row.exists && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onDeleteData(row)}
                                >
                                    <Trash2 className="size-4" aria-hidden />
                                    {t('Delete data')}
                                </Button>
                            )}
                        </div>
                    </div>
                    <div
                        role="img"
                        aria-label={t(':size of :largest, the largest volume', {
                            size: formatBytes(row.bytes),
                            largest: formatBytes(largest),
                        })}
                        className="h-2 w-full overflow-hidden rounded-full bg-base-200"
                    >
                        <div
                            className="h-full rounded-full bg-primary"
                            style={{
                                width: `${row.bytes === 0 ? 0 : Math.max(2, (row.bytes / largest) * 100)}%`,
                            }}
                        />
                    </div>
                    <p className="text-xs text-base-content/70">
                        {row.exists
                            ? row.partial
                                ? tn(
                                      'one::size in :count file or more (too big to count completely)|other::size in :count files or more (too big to count completely)',
                                      row.files,
                                      { size: formatBytes(row.bytes) },
                                  )
                                : tn(
                                      'one::size in :count file|other::size in :count files',
                                      row.files,
                                      { size: formatBytes(row.bytes) },
                                  )
                            : t(
                                  'No files yet: the folder is created when a container that uses it starts.',
                              )}
                        {row.attached ? (
                            <>
                                {' · '}
                                <Trans
                                    text="Mounted at <code>:path</code>, kept on the server as <code>:name</code>"
                                    components={{ code: <code /> }}
                                    values={{
                                        path: row.path ?? '',
                                        name: row.name,
                                    }}
                                />
                            </>
                        ) : (
                            <>
                                {' · '}
                                <Trans
                                    text="Kept on the server as <code>:name</code>"
                                    components={{ code: <code /> }}
                                    values={{ name: row.name }}
                                />
                            </>
                        )}
                    </p>
                </li>
            ))}
        </ul>
    );
}
