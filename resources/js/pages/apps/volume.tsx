import { Link } from '@inertiajs/react';
import { ArrowLeft, ChevronRight, Download, HardDrive } from 'lucide-react';
import { useState } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import AppVolumeFilesController from '@/actions/App/Http/Controllers/AppVolumeFilesController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { FileIcon } from '@/components/ui/file-icon';
import { LockIcon } from '@/components/ui/lock-icon';
import { Modal } from '@/components/ui/modal';
import AppLayout from '@/layouts/app-layout';
import { formatBytes, formatDate } from '@/lib/format';
import type { AppSummary } from '@/types';

type Entry = {
    name: string;
    kind: 'dir' | 'file' | 'link' | 'other';
    size: number;
    modified: string | null;
};

type Props = {
    app: AppSummary;
    volume: {
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
    path: string;
    entries: Entry[];
    folder: {
        files: number;
        folders: number;
        other: number;
        truncated: boolean;
    };
    error: string | null;
};

type Preview = {
    path: string;
    size: number;
    content: string;
    binary: boolean;
    truncated: boolean;
};

const plural = (n: number, one: string, many: string) =>
    `${n.toLocaleString()} ${n === 1 ? one : many}`;

function Stat({
    label,
    value,
    note,
}: {
    label: string;
    value: string;
    note?: string;
}) {
    return (
        <div className="space-y-1">
            <dt className="text-sm text-base-content/70">{label}</dt>
            <dd className="text-lg font-semibold">{value}</dd>
            {note && <p className="text-xs text-base-content/60">{note}</p>}
        </div>
    );
}

/**
 * One volume of an app: whether it is attached, its totals, and a read-only
 * view of its folders. A folder is a page of its own (the path is in the
 * address), so the back button and links work. Files are plain text here:
 * nothing from a volume is ever rendered as HTML.
 */
export default function Volume({
    app,
    volume,
    path,
    entries,
    folder,
    error,
}: Props) {
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);

    const route = { app: app.slug, name: volume.name };
    const folderUrl = (to: string) =>
        to === ''
            ? AppVolumeFilesController.show.url(route)
            : AppVolumeFilesController.show.url(route, { query: { path: to } });
    const downloadUrl = (file: string) =>
        AppVolumeFilesController.download.url(route, { query: { path: file } });

    const join = (name: string) => (path === '' ? name : `${path}/${name}`);
    const segments = path === '' ? [] : path.split('/');
    const parent = segments.slice(0, -1).join('/');

    const open = async (entry: Entry) => {
        setPreviewError(null);

        try {
            const response = await fetch(
                AppVolumeFilesController.file.url(route, {
                    query: { path: join(entry.name) },
                }),
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );
            const body = (await response.json()) as Preview & {
                error?: string;
            };

            if (!response.ok)
                throw new Error(body.error ?? 'The file could not be read.');

            setPreview(body);
        } catch (e) {
            setPreviewError(
                e instanceof Error ? e.message : 'The file could not be read.',
            );
        }
    };

    return (
        <AppLayout title={`${volume.path ?? volume.name} · ${app.name}`}>
            <PageHeader
                title={volume.path ?? volume.name}
                badge={
                    <>
                        <span
                            className={`badge-soft badge ${volume.attached ? 'badge-success' : 'badge-warning'}`}
                        >
                            {volume.attached ? 'Attached' : 'Not attached'}
                        </span>
                        {volume.locked && <LockIcon what="volume" />}
                    </>
                }
                description={
                    volume.attached
                        ? `Browsing a volume of ${app.name}. Mounted at ${volume.path} in its containers, kept on the server as ${volume.name}.`
                        : `Browsing files that are no longer mounted by ${app.name}. Kept on the server as ${volume.name}.`
                }
                actions={
                    <Link
                        href={`${AppController.show.url(app.slug)}?tab=volumes`}
                        className="btn btn-soft"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        All volumes
                    </Link>
                }
            />

            {error && <Alert variant="error">{error}</Alert>}

            <Card title="Totals">
                <dl className="grid gap-6 sm:grid-cols-3">
                    <Stat
                        label="In this folder"
                        value={plural(folder.files, 'file', 'files')}
                        note={`${plural(folder.folders, 'folder', 'folders')}${folder.other > 0 ? `, ${plural(folder.other, 'other item', 'other items')}` : ''}${folder.truncated ? ' (more than are shown)' : ''}`}
                    />
                    <Stat
                        label="In the whole volume"
                        value={`${plural(volume.files, 'file', 'files')}${volume.partial ? '+' : ''}`}
                        note={
                            volume.partial
                                ? 'Too big to count completely'
                                : 'Including all subfolders'
                        }
                    />
                    <Stat
                        label="Disk space used"
                        value={formatBytes(volume.bytes)}
                        note="By the whole volume"
                    />
                </dl>
            </Card>

            <Card
                title="Files"
                description="Read only. Click a folder to open it, a file to preview it, and the arrow to download it. Links and special files are shown but never opened."
                flush
            >
                <nav
                    aria-label="Folder"
                    className="flex flex-wrap items-center gap-1 border-b border-base-300 px-5 py-3 text-sm sm:px-6"
                >
                    <HardDrive
                        className="size-4 text-base-content/60"
                        aria-hidden
                    />
                    {segments.length === 0 ? (
                        <span className="font-medium">{volume.name}</span>
                    ) : (
                        <Link
                            href={folderUrl('')}
                            className="link link-primary"
                        >
                            {volume.name}
                        </Link>
                    )}
                    {segments.map((segment, i) => (
                        <span key={i} className="flex items-center gap-1">
                            <ChevronRight
                                className="size-3.5 text-base-content/60"
                                aria-hidden
                            />
                            {i === segments.length - 1 ? (
                                <span className="font-medium">{segment}</span>
                            ) : (
                                <Link
                                    href={folderUrl(
                                        segments.slice(0, i + 1).join('/'),
                                    )}
                                    className="link link-primary"
                                >
                                    {segment}
                                </Link>
                            )}
                        </span>
                    ))}
                </nav>

                {!volume.exists && !error ? (
                    <p className="p-5 text-sm text-base-content/70 sm:p-6">
                        There are no files yet: the folder is created when a
                        container that uses this volume starts.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th className="text-right">Size</th>
                                    <th>Modified</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {path !== '' && (
                                    <tr>
                                        <td colSpan={4}>
                                            <Link
                                                href={folderUrl(parent)}
                                                className="flex items-center gap-2 hover:underline"
                                            >
                                                <FileIcon name=".." kind="up" />
                                                <span>..</span>
                                            </Link>
                                        </td>
                                    </tr>
                                )}
                                {entries.map((entry) => (
                                    <tr key={entry.name}>
                                        <td className="max-w-xs">
                                            {entry.kind === 'dir' ? (
                                                <Link
                                                    href={folderUrl(
                                                        join(entry.name),
                                                    )}
                                                    className="flex items-center gap-2 hover:underline"
                                                >
                                                    <FileIcon
                                                        name={entry.name}
                                                        kind="dir"
                                                    />
                                                    <span className="truncate">
                                                        {entry.name}
                                                    </span>
                                                </Link>
                                            ) : entry.kind === 'file' ? (
                                                <button
                                                    type="button"
                                                    className="flex items-center gap-2 text-left hover:underline"
                                                    onClick={() =>
                                                        void open(entry)
                                                    }
                                                >
                                                    <FileIcon
                                                        name={entry.name}
                                                        kind="file"
                                                    />
                                                    <span className="truncate">
                                                        {entry.name}
                                                    </span>
                                                </button>
                                            ) : (
                                                <span className="flex items-center gap-2 text-base-content/70">
                                                    <FileIcon
                                                        name={entry.name}
                                                        kind={entry.kind}
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
                                        <td className="text-right">
                                            {entry.kind === 'file' && (
                                                <a
                                                    href={downloadUrl(
                                                        join(entry.name),
                                                    )}
                                                    className="btn btn-text btn-sm"
                                                    aria-label={`Download ${entry.name}`}
                                                >
                                                    <Download
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                </a>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {entries.length === 0 &&
                                    path === '' &&
                                    volume.exists && (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="text-base-content/70"
                                            >
                                                This volume is empty.
                                            </td>
                                        </tr>
                                    )}
                                {entries.length === 0 &&
                                    path !== '' &&
                                    !error && (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="text-base-content/70"
                                            >
                                                This folder is empty.
                                            </td>
                                        </tr>
                                    )}
                            </tbody>
                        </table>
                    </div>
                )}
                {folder.truncated && (
                    <p className="border-t border-base-300 px-5 py-3 text-sm text-base-content/70 sm:px-6">
                        This folder has more items than are shown here.
                    </p>
                )}
                {previewError && (
                    <div className="border-t border-base-300 p-5 sm:p-6">
                        <Alert variant="error">{previewError}</Alert>
                    </div>
                )}
            </Card>

            <Modal
                open={preview !== null}
                onClose={() => setPreview(null)}
                title={preview?.path ?? 'File'}
                size="lg"
            >
                {preview && (
                    <div className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm text-base-content/70">
                                {formatBytes(preview.size)}
                            </p>
                            <a
                                href={downloadUrl(preview.path)}
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
                                    tabIndex={0}
                                    className="max-h-96 overflow-auto rounded-field bg-base-200 p-3 font-mono text-xs whitespace-pre"
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
                        <div className="flex justify-end">
                            <Button
                                variant="ghost"
                                onClick={() => setPreview(null)}
                            >
                                Close
                            </Button>
                        </div>
                    </div>
                )}
            </Modal>
        </AppLayout>
    );
}
