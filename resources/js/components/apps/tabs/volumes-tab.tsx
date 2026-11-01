import { router, usePage } from '@inertiajs/react';
import {
    ChevronRight,
    Download,
    File,
    FileSymlink,
    Folder,
    FolderOpen,
    HardDrive,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import AppVolumeFilesController from '@/actions/App/Http/Controllers/AppVolumeFilesController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { formatBytes, formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AppSettings } from '@/types';

type VolumeRow = {
    name: string;
    path: string | null;
    attached: boolean;
    exists: boolean;
    bytes: number;
    files: number;
    partial: boolean;
};
type Overview = { volumes: VolumeRow[]; total_bytes: number; running: boolean };
type Entry = {
    name: string;
    kind: 'dir' | 'file' | 'link' | 'other';
    size: number;
    modified: string | null;
};
type Listing = { path: string; entries: Entry[]; truncated: boolean };
type Preview = {
    path: string;
    size: number;
    content: string;
    binary: boolean;
    truncated: boolean;
};

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const body = (await response.json().catch(() => ({}))) as T & {
        error?: string;
    };

    if (!response.ok) {
        throw new Error(
            body.error ?? `The request failed (${response.status}).`,
        );
    }

    return body;
}

const withPath = (url: string, path: string) =>
    path === '' ? url : `${url}?path=${encodeURIComponent(path)}`;

/**
 * How much space each volume uses, and a read-only look at its files. Only
 * mounted while the tab is open. Files come from the app, so everything shown
 * is plain text; nothing is rendered as HTML.
 */
export function VolumesTab({ app }: { app: AppSettings }) {
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();
    const [overview, setOverview] = useState<Overview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [current, setCurrent] = useState<string | null>(null);

    const load = useCallback(async () => {
        try {
            setOverview(
                await getJson<Overview>(
                    AppVolumeFilesController.index(app.slug).url,
                ),
            );
            setError(null);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'The volumes could not be loaded.',
            );
        }
    }, [app.slug]);

    useEffect(() => {
        void load();
    }, [load]);

    const deleteData = (row: VolumeRow) =>
        void confirmed.run(() => {
            if (
                !window.confirm(
                    `Delete all files in ${row.name}? This cannot be undone.`,
                )
            ) {
                return;
            }

            router.delete(
                AppVolumeFilesController.destroyData({
                    app: app.slug,
                    name: row.name,
                }),
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        if (current === row.name) setCurrent(null);
                        void load();
                    },
                },
            );
        });

    if (error) return <Alert variant="error">{error}</Alert>;

    if (overview === null) {
        return (
            <p className="text-sm text-base-content/70" role="status">
                Loading...
            </p>
        );
    }

    const largest = Math.max(1, ...overview.volumes.map((v) => v.bytes));

    return (
        <div className="space-y-6">
            {errors.volume && <Alert variant="error">{errors.volume}</Alert>}

            <Card
                title="Volumes"
                description={
                    overview.volumes.length === 0
                        ? 'Folders the app keeps between deployments.'
                        : `${overview.volumes.length} ${overview.volumes.length === 1 ? 'volume' : 'volumes'} using ${formatBytes(overview.total_bytes)} on this server.`
                }
                actions={
                    <Button
                        variant="soft"
                        size="sm"
                        onClick={() => void load()}
                    >
                        <RefreshCw className="size-4" aria-hidden />
                        Refresh
                    </Button>
                }
            >
                {overview.volumes.length === 0 ? (
                    <p className="text-sm text-base-content/70">
                        This app has no persistent volumes. Add one in Settings
                        to keep files between deployments.
                    </p>
                ) : (
                    <ul className="divide-y divide-base-300 rounded-field border border-base-300">
                        {overview.volumes.map((row) => (
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
                                        {!row.attached && (
                                            <span className="badge-soft badge badge-sm badge-warning">
                                                Not attached
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-1">
                                        {row.exists && (
                                            <Button
                                                variant="soft"
                                                size="sm"
                                                onClick={() =>
                                                    setCurrent(row.name)
                                                }
                                                disabled={current === row.name}
                                            >
                                                <FolderOpen
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                                Browse
                                            </Button>
                                        )}
                                        {!row.attached && row.exists && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => deleteData(row)}
                                            >
                                                <Trash2
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                                Delete data
                                            </Button>
                                        )}
                                    </div>
                                </div>
                                <div
                                    role="img"
                                    aria-label={`${formatBytes(row.bytes)} of ${formatBytes(largest)}, the largest volume`}
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
                                        ? `${formatBytes(row.bytes)} in ${row.files.toLocaleString()} ${row.files === 1 ? 'file' : 'files'}${row.partial ? ' or more (too big to count completely)' : ''}`
                                        : 'No files yet: the folder is created when a container that uses it starts.'}
                                    {' · '}Folder: {row.name}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
                {overview.volumes.some((v) => !v.attached) && (
                    <p className="text-sm text-base-content/70">
                        A volume that is not attached is no longer mounted from
                        new deployments. Its files stay until you delete them
                        here, which is only possible once no container has it
                        mounted: deploy the app again first.
                    </p>
                )}
            </Card>

            {current && (
                <FileBrowser
                    key={current}
                    slug={app.slug}
                    volume={current}
                    onClose={() => setCurrent(null)}
                />
            )}
            {confirmed.modal}
        </div>
    );
}

function FileBrowser({
    slug,
    volume,
    onClose,
}: {
    slug: string;
    volume: string;
    onClose: () => void;
}) {
    const [path, setPath] = useState('');
    const [listing, setListing] = useState<Listing | null>(null);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);

    const route = { app: slug, name: volume };

    useEffect(() => {
        let cancelled = false;
        setListing(null);

        getJson<Listing>(
            withPath(AppVolumeFilesController.browse(route).url, path),
        )
            .then((l) => {
                if (!cancelled) {
                    setListing(l);
                    setError(null);
                }
            })
            .catch((e: unknown) => {
                if (!cancelled) {
                    setError(
                        e instanceof Error
                            ? e.message
                            : 'The folder could not be read.',
                    );
                }
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [slug, volume, path]);

    const join = (name: string) => (path === '' ? name : `${path}/${name}`);

    const open = (entry: Entry) => {
        if (entry.kind === 'dir') {
            setPreview(null);
            setPath(join(entry.name));
        } else if (entry.kind === 'file') {
            setError(null);
            getJson<Preview>(
                withPath(
                    AppVolumeFilesController.file(route).url,
                    join(entry.name),
                ),
            )
                .then(setPreview)
                .catch((e: unknown) =>
                    setError(
                        e instanceof Error
                            ? e.message
                            : 'The file could not be read.',
                    ),
                );
        }
    };

    const segments = path === '' ? [] : path.split('/');

    return (
        <Card
            title={`Files in ${volume}`}
            description="Read only. Links inside a volume are shown but never followed."
            actions={
                <Button variant="ghost" size="sm" onClick={onClose}>
                    Close
                </Button>
            }
        >
            {error && <Alert variant="error">{error}</Alert>}

            <nav
                aria-label="Folder"
                className="flex flex-wrap items-center gap-1 text-sm"
            >
                <button
                    type="button"
                    className="link link-primary"
                    onClick={() => {
                        setPreview(null);
                        setPath('');
                    }}
                >
                    {volume}
                </button>
                {segments.map((segment, i) => (
                    <span key={i} className="flex items-center gap-1">
                        <ChevronRight
                            className="size-3.5 text-base-content/60"
                            aria-hidden
                        />
                        <button
                            type="button"
                            className="link link-primary"
                            onClick={() => {
                                setPreview(null);
                                setPath(segments.slice(0, i + 1).join('/'));
                            }}
                        >
                            {segment}
                        </button>
                    </span>
                ))}
            </nav>

            {listing === null ? (
                !error && (
                    <p className="text-sm text-base-content/70" role="status">
                        Loading...
                    </p>
                )
            ) : listing.entries.length === 0 ? (
                <p className="text-sm text-base-content/70">
                    This folder is empty.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-field border border-base-300">
                    <table className="table w-full text-sm">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th className="text-right">Size</th>
                                <th>Modified</th>
                            </tr>
                        </thead>
                        <tbody>
                            {listing.entries.map((entry) => (
                                <tr key={entry.name}>
                                    <td className="max-w-xs">
                                        {entry.kind === 'dir' ||
                                        entry.kind === 'file' ? (
                                            <button
                                                type="button"
                                                className="flex items-center gap-2 text-left hover:underline"
                                                onClick={() => open(entry)}
                                            >
                                                {entry.kind === 'dir' ? (
                                                    <Folder
                                                        className="size-4 shrink-0 text-primary"
                                                        aria-hidden
                                                    />
                                                ) : (
                                                    <File
                                                        className="size-4 shrink-0 text-base-content/70"
                                                        aria-hidden
                                                    />
                                                )}
                                                <span className="truncate">
                                                    {entry.name}
                                                </span>
                                            </button>
                                        ) : (
                                            <span className="flex items-center gap-2 text-base-content/70">
                                                <FileSymlink
                                                    className="size-4 shrink-0"
                                                    aria-hidden
                                                />
                                                <span className="truncate">
                                                    {entry.name}
                                                </span>
                                                <span className="text-xs">
                                                    (
                                                    {entry.kind === 'link'
                                                        ? 'link'
                                                        : 'special file'}
                                                    , not opened)
                                                </span>
                                            </span>
                                        )}
                                    </td>
                                    <td className="text-right whitespace-nowrap">
                                        {entry.kind === 'file'
                                            ? formatBytes(entry.size)
                                            : ''}
                                    </td>
                                    <td className="whitespace-nowrap text-base-content/70">
                                        {entry.modified
                                            ? formatDate(entry.modified)
                                            : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {listing?.truncated && (
                <p className="text-sm text-base-content/70">
                    This folder has more items than are shown here.
                </p>
            )}

            {preview && (
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="min-w-0 truncate text-sm font-medium">
                            {preview.path}{' '}
                            <span className="font-normal text-base-content/70">
                                ({formatBytes(preview.size)})
                            </span>
                        </p>
                        <a
                            href={withPath(
                                AppVolumeFilesController.download(route).url,
                                preview.path,
                            )}
                            className="btn btn-soft btn-sm"
                        >
                            <Download className="size-4" aria-hidden />
                            Download
                        </a>
                    </div>
                    {preview.binary ? (
                        <p className="text-sm text-base-content/70">
                            This is not a text file, so there is no preview.
                            Download it to open it.
                        </p>
                    ) : (
                        <>
                            <pre
                                className={cn(
                                    'max-h-96 overflow-auto rounded-field bg-base-200 p-3 font-mono text-xs whitespace-pre',
                                )}
                                tabIndex={0}
                            >
                                {preview.content === ''
                                    ? '(empty file)'
                                    : preview.content}
                            </pre>
                            {preview.truncated && (
                                <p className="text-sm text-base-content/70">
                                    Only the start of the file is shown.
                                    Download it to see all of it.
                                </p>
                            )}
                        </>
                    )}
                </div>
            )}
        </Card>
    );
}
