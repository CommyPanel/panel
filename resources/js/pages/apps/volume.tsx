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
import { useT } from '@/hooks/use-t';
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
    const { t, tn } = useT();
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);

    const folderNote = (() => {
        const folders = tn(
            'one::count folder|other::count folders',
            folder.folders,
        );
        const base =
            folder.other > 0
                ? t(':folders, :other', {
                      folders,
                      other: tn(
                          'one::count other item|other::count other items',
                          folder.other,
                      ),
                  })
                : folders;

        return folder.truncated
            ? t(':note (more than are shown)', { note: base })
            : base;
    })();

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
                throw new Error(body.error ?? t('The file could not be read.'));

            setPreview(body);
        } catch (e) {
            setPreviewError(
                e instanceof Error
                    ? e.message
                    : t('The file could not be read.'),
            );
        }
    };

    return (
        <AppLayout
            title={t(':volume · :app', {
                volume: volume.path ?? volume.name,
                app: app.name,
            })}
        >
            <PageHeader
                title={volume.path ?? volume.name}
                badge={
                    <>
                        <span
                            className={`badge-soft badge ${volume.attached ? 'badge-success' : 'badge-warning'}`}
                        >
                            {volume.attached
                                ? t('Attached')
                                : t('Not attached')}
                        </span>
                        {volume.locked && <LockIcon what="volume" />}
                    </>
                }
                description={
                    volume.attached
                        ? t(
                              'Browsing a volume of :app. Mounted at :path in its containers, kept on the server as :name.',
                              {
                                  app: app.name,
                                  path: volume.path ?? '',
                                  name: volume.name,
                              },
                          )
                        : t(
                              'Browsing files that are no longer mounted by :app. Kept on the server as :name.',
                              { app: app.name, name: volume.name },
                          )
                }
                actions={
                    <Link
                        href={`${AppController.show.url(app.slug)}?tab=volumes`}
                        className="btn btn-soft"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        {t('All volumes')}
                    </Link>
                }
            />

            {error && <Alert variant="error">{error}</Alert>}

            <Card title={t('Totals')}>
                <dl className="grid gap-6 sm:grid-cols-3">
                    <Stat
                        label={t('In this folder')}
                        value={tn(
                            'one::count file|other::count files',
                            folder.files,
                        )}
                        note={folderNote}
                    />
                    <Stat
                        label={t('In the whole volume')}
                        value={`${tn('one::count file|other::count files', volume.files)}${volume.partial ? '+' : ''}`}
                        note={
                            volume.partial
                                ? t('Too big to count completely')
                                : t('Including all subfolders')
                        }
                    />
                    <Stat
                        label={t('Disk space used')}
                        value={formatBytes(volume.bytes)}
                        note={t('By the whole volume')}
                    />
                </dl>
            </Card>

            <Card
                title={t('Files')}
                description={t(
                    'Read only. Click a folder to open it, a file to preview it, and the arrow to download it. Links and special files are shown but never opened.',
                )}
                flush
            >
                <nav
                    aria-label={t('Folder')}
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
                        {t(
                            'There are no files yet: the folder is created when a container that uses this volume starts.',
                        )}
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>{t('Name')}</th>
                                    <th className="text-right">{t('Size')}</th>
                                    <th>{t('Modified')}</th>
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
                                                        {entry.kind === 'link'
                                                            ? t(
                                                                  '(link, not opened)',
                                                              )
                                                            : t(
                                                                  '(special file, not opened)',
                                                              )}
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
                                                    aria-label={t(
                                                        'Download :name',
                                                        {
                                                            name: entry.name,
                                                        },
                                                    )}
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
                                                {t('This volume is empty.')}
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
                                                {t('This folder is empty.')}
                                            </td>
                                        </tr>
                                    )}
                            </tbody>
                        </table>
                    </div>
                )}
                {folder.truncated && (
                    <p className="border-t border-base-300 px-5 py-3 text-sm text-base-content/70 sm:px-6">
                        {t('This folder has more items than are shown here.')}
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
                title={preview?.path ?? t('File')}
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
                                {t('Download')}
                            </a>
                        </div>
                        {preview.binary ? (
                            <p className="text-sm text-base-content/70">
                                {t(
                                    'This is not a text file, so there is no preview. Download it to open it.',
                                )}
                            </p>
                        ) : (
                            <>
                                <pre
                                    tabIndex={0}
                                    className="max-h-96 overflow-auto rounded-field bg-base-200 p-3 font-mono text-xs whitespace-pre"
                                >
                                    {preview.content === ''
                                        ? t('(empty file)')
                                        : preview.content}
                                </pre>
                                {preview.truncated && (
                                    <p className="text-sm text-base-content/70">
                                        {t(
                                            'Only the start of the file is shown. Download it to see all of it.',
                                        )}
                                    </p>
                                )}
                            </>
                        )}
                        <div className="flex justify-end">
                            <Button
                                variant="ghost"
                                onClick={() => setPreview(null)}
                            >
                                {t('Close')}
                            </Button>
                        </div>
                    </div>
                )}
            </Modal>
        </AppLayout>
    );
}
