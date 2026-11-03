import { Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArrowLeft,
    Download,
    ExternalLink,
    KeyRound,
    Play,
    Plus,
    RefreshCw,
    RotateCcw,
    RotateCw,
    Square,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import DatabaseBackupController from '@/actions/App/Http/Controllers/DatabaseBackupController';
import DatabaseServerController from '@/actions/App/Http/Controllers/DatabaseServerController';
import DatabaseToolController from '@/actions/App/Http/Controllers/DatabaseToolController';
import ManagedDatabaseController from '@/actions/App/Http/Controllers/ManagedDatabaseController';
import { AddDatabaseModal } from '@/components/databases/add-database-modal';
import { CredentialsModal } from '@/components/databases/credentials-modal';
import { DatabaseStatusBadge } from '@/components/databases/database-status';
import { RestoreBackupModal } from '@/components/databases/restore-backup-modal';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { TypedConfirmModal } from '@/components/ui/typed-confirm-modal';
import { formatBytes, formatDate, timeAgo } from '@/lib/format';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/lib/permissions';
import { jsonHeaders } from '@/lib/post-json';
import type {
    DatabaseBackupFile,
    DatabaseCredentials,
    DatabaseServer,
    ManagedDatabase,
} from '@/types';

type Props = {
    server: DatabaseServer;
    databases: ManagedDatabase[];
    backups: DatabaseBackupFile[];
    keep: number;
};

export default function Show({ server, databases, backups, keep }: Props) {
    const can = useCan();
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();
    const [adding, setAdding] = useState(false);
    const [credentials, setCredentials] = useState<DatabaseCredentials | null>(
        null,
    );
    const [credentialsError, setCredentialsError] = useState<string | null>(
        null,
    );
    const [dropping, setDropping] = useState<ManagedDatabase | null>(null);
    const [rotating, setRotating] = useState<ManagedDatabase | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [removingBackup, setRemovingBackup] =
        useState<DatabaseBackupFile | null>(null);
    const [restoringFile, setRestoringFile] =
        useState<DatabaseBackupFile | null>(null);
    const [restoreError, setRestoreError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    const manage = can('databases.manage');
    const running = server.status === 'running';

    // While the server starts, look again every few seconds.
    const restoring = databases.some((d) => d.restore?.status === 'restoring');

    useEffect(() => {
        if (server.status !== 'provisioning' && !restoring) return;

        const timer = setInterval(
            () => router.reload({ only: ['server', 'databases'] }),
            3000,
        );

        return () => clearInterval(timer);
    }, [server.status, restoring]);

    const post = (url: ReturnType<typeof DatabaseServerController.start>) =>
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    const showCredentials = (database: ManagedDatabase) =>
        void confirmed.run(async () => {
            setCredentialsError(null);

            try {
                const response = await fetch(
                    ManagedDatabaseController.credentials({
                        server: server.slug,
                        database: database.id,
                    }).url,
                    { credentials: 'same-origin', headers: jsonHeaders() },
                );

                if (!response.ok) {
                    throw new Error(
                        response.status === 423
                            ? 'Confirm your password and try again.'
                            : 'The connection could not be loaded.',
                    );
                }

                setCredentials((await response.json()) as DatabaseCredentials);
            } catch (e) {
                setCredentialsError(
                    e instanceof Error
                        ? e.message
                        : 'The connection could not be loaded.',
                );
            }
        });

    const rotate = () => {
        if (!rotating) return;

        void confirmed.run(() =>
            router.post(
                ManagedDatabaseController.password({
                    server: server.slug,
                    database: rotating.id,
                }),
                {},
                {
                    preserveScroll: true,
                    onStart: () => setBusy(true),
                    onFinish: () => {
                        setBusy(false);
                        setRotating(null);
                    },
                },
            ),
        );
    };

    const drop = (typed: string) => {
        if (!dropping) return;

        void confirmed.run(() =>
            router.delete(
                ManagedDatabaseController.destroy({
                    server: server.slug,
                    database: dropping.id,
                }),
                {
                    data: { confirm: typed },
                    preserveScroll: true,
                    onStart: () => setBusy(true),
                    onFinish: () => setBusy(false),
                    onSuccess: () => setDropping(null),
                },
            ),
        );
    };

    const backUp = (database: ManagedDatabase) =>
        router.post(
            DatabaseBackupController.store({
                server: server.slug,
                database: database.id,
            }),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    const downloadBackup = (file: DatabaseBackupFile) =>
        void confirmed.run(() => {
            window.location.href = DatabaseBackupController.download({
                server: server.slug,
                name: file.name,
            }).url;
        });

    const restoreBackup = (name: string) => {
        if (!restoringFile) return;

        void confirmed.run(() =>
            router.post(
                DatabaseBackupController.restore({
                    server: server.slug,
                    name: restoringFile.name,
                }),
                { database: name },
                {
                    preserveScroll: true,
                    onStart: () => {
                        setBusy(true);
                        setRestoreError(undefined);
                    },
                    onSuccess: () => setRestoringFile(null),
                    onError: (errors) => setRestoreError(errors.database),
                    onFinish: () => setBusy(false),
                },
            ),
        );
    };

    const deleteBackup = () => {
        if (!removingBackup) return;

        void confirmed.run(() =>
            router.delete(
                DatabaseBackupController.destroy({
                    server: server.slug,
                    name: removingBackup.name,
                }),
                {
                    preserveScroll: true,
                    onFinish: () => setRemovingBackup(null),
                },
            ),
        );
    };

    const deleteServer = (typed: string) =>
        void confirmed.run(() =>
            router.delete(DatabaseServerController.destroy(server.slug), {
                data: { confirm: typed },
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            }),
        );

    return (
        <AppLayout title={server.name}>
            <PageHeader
                title={server.name}
                badge={<DatabaseStatusBadge status={server.status} />}
                description={`${server.engine_label} ${server.version}. Apps that are linked to one of its databases reach it as ${server.host} on port ${server.port}.`}
                actions={
                    <>
                        <Link
                            href={DatabaseServerController.index()}
                            className="btn btn-soft"
                        >
                            <ArrowLeft className="size-4" aria-hidden />
                            All servers
                        </Link>
                        {manage && running && (
                            <>
                                <Button
                                    variant="soft"
                                    loading={busy}
                                    onClick={() =>
                                        post(
                                            DatabaseServerController.restart(
                                                server.slug,
                                            ),
                                        )
                                    }
                                >
                                    <RotateCw className="size-4" aria-hidden />
                                    Restart
                                </Button>
                                <Button
                                    variant="warning"
                                    loading={busy}
                                    onClick={() =>
                                        post(
                                            DatabaseServerController.stop(
                                                server.slug,
                                            ),
                                        )
                                    }
                                >
                                    <Square className="size-4" aria-hidden />
                                    Stop
                                </Button>
                            </>
                        )}
                        {manage && server.status === 'stopped' && (
                            <Button
                                loading={busy}
                                onClick={() =>
                                    post(
                                        DatabaseServerController.start(
                                            server.slug,
                                        ),
                                    )
                                }
                            >
                                <Play className="size-4" aria-hidden />
                                Start
                            </Button>
                        )}
                    </>
                }
            />

            {errors.database && (
                <Alert variant="error">{errors.database}</Alert>
            )}
            {credentialsError && (
                <Alert variant="error">{credentialsError}</Alert>
            )}

            {server.status === 'provisioning' && (
                <Alert variant="info">
                    The server is starting. The first start downloads the image,
                    which can take a few minutes. This page updates by itself.
                </Alert>
            )}

            {server.status === 'failed' && (
                <Alert variant="error">
                    <span className="flex flex-wrap items-center justify-between gap-3">
                        <span>
                            The server could not start
                            {server.error ? `: ${server.error}` : '.'}
                        </span>
                        {manage && (
                            <Button
                                variant="soft"
                                size="sm"
                                loading={busy}
                                onClick={() =>
                                    post(
                                        DatabaseServerController.retry(
                                            server.slug,
                                        ),
                                    )
                                }
                            >
                                <RefreshCw className="size-4" aria-hidden />
                                Try again
                            </Button>
                        )}
                    </span>
                </Alert>
            )}

            <Card
                title="Databases"
                description="Each database has one user that can use it and nothing else on this server."
                actions={
                    manage &&
                    running && (
                        <Button size="sm" onClick={() => setAdding(true)}>
                            <Plus className="size-4" aria-hidden />
                            Add database
                        </Button>
                    )
                }
                flush={databases.length > 0}
            >
                {databases.length === 0 ? (
                    <p className="text-sm text-base-content/70">
                        {running
                            ? 'No databases yet. Add one, then link it to an app on that app’s Links tab.'
                            : 'Databases can be added once the server is running.'}
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Database</th>
                                    <th>Used by</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {databases.map((database) => (
                                    <tr key={database.id}>
                                        <td>
                                            <code>{database.name}</code>
                                            {database.restore?.status ===
                                                'restoring' && (
                                                <span className="badge-soft ml-2 badge badge-info">
                                                    Restoring…
                                                </span>
                                            )}
                                            {database.restore?.status ===
                                                'failed' && (
                                                <>
                                                    <span className="badge-soft ml-2 badge badge-error">
                                                        Restore failed
                                                    </span>
                                                    <p className="mt-1 max-w-sm text-sm break-words text-base-content/70">
                                                        {database.restore.error}
                                                    </p>
                                                </>
                                            )}
                                        </td>
                                        <td>
                                            {database.apps.length === 0 ? (
                                                <span className="text-base-content/70">
                                                    No app
                                                </span>
                                            ) : (
                                                database.apps.map((app, i) => (
                                                    <span key={app.slug}>
                                                        {i > 0 && ', '}
                                                        {app.name}
                                                    </span>
                                                ))
                                            )}
                                        </td>
                                        <td className="min-w-72">
                                            <div className="flex flex-wrap justify-end gap-1">
                                                {can(
                                                    'databases.credentials',
                                                ) && (
                                                    <Button
                                                        variant="soft"
                                                        size="sm"
                                                        onClick={() =>
                                                            showCredentials(
                                                                database,
                                                            )
                                                        }
                                                    >
                                                        <KeyRound
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        Connection
                                                    </Button>
                                                )}
                                                {can('databases.tools') &&
                                                    running &&
                                                    !database.restore && (
                                                        <a
                                                            href={
                                                                DatabaseToolController.show(
                                                                    {
                                                                        server: server.slug,
                                                                        database:
                                                                            database.id,
                                                                    },
                                                                ).url
                                                            }
                                                            target="_blank"
                                                            rel="noopener"
                                                            className="btn btn-soft btn-sm"
                                                            title={`Opens ${server.tool} in a new tab, logged in to this database only. It closes by itself after 15 minutes without activity.`}
                                                        >
                                                            <ExternalLink
                                                                className="size-4"
                                                                aria-hidden
                                                            />
                                                            {server.tool}
                                                        </a>
                                                    )}
                                                {manage && running && (
                                                    <>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            loading={busy}
                                                            onClick={() =>
                                                                backUp(database)
                                                            }
                                                        >
                                                            <Archive
                                                                className="size-4"
                                                                aria-hidden
                                                            />
                                                            Back up
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setRotating(
                                                                    database,
                                                                )
                                                            }
                                                        >
                                                            <RotateCw
                                                                className="size-4"
                                                                aria-hidden
                                                            />
                                                            New password
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setDropping(
                                                                    database,
                                                                )
                                                            }
                                                        >
                                                            <Trash2
                                                                className="size-4"
                                                                aria-hidden
                                                            />
                                                            Delete
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Card
                title="Backups"
                description={`Every database is dumped to a file each night at 04:00, and the newest ${keep} of each are kept on this server. A dump is plain SQL that recreates the database.`}
                flush={backups.length > 0}
            >
                {backups.length === 0 ? (
                    <p className="text-sm text-base-content/70">
                        No backup yet. The first is made tonight, or press Back
                        up next to a database.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Database</th>
                                    <th>Made</th>
                                    <th className="text-right">Size</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {backups.map((file) => (
                                    <tr key={file.name}>
                                        <td>
                                            <code>{file.database}</code>
                                        </td>
                                        <td
                                            className="whitespace-nowrap"
                                            title={formatDate(file.created_at)}
                                        >
                                            {timeAgo(file.created_at)}
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            {formatBytes(file.size)}
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            {manage && (
                                                <>
                                                    {running && (
                                                        <Button
                                                            variant="soft"
                                                            size="sm"
                                                            onClick={() => {
                                                                setRestoreError(
                                                                    undefined,
                                                                );
                                                                setRestoringFile(
                                                                    file,
                                                                );
                                                            }}
                                                        >
                                                            <RotateCcw
                                                                className="size-4"
                                                                aria-hidden
                                                            />
                                                            Restore
                                                        </Button>
                                                    )}
                                                    <Button
                                                        variant="soft"
                                                        size="sm"
                                                        onClick={() =>
                                                            downloadBackup(file)
                                                        }
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
                                                        onClick={() =>
                                                            setRemovingBackup(
                                                                file,
                                                            )
                                                        }
                                                    >
                                                        <Trash2
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        Delete
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
                <p className="text-sm text-base-content/70">
                    Dumps stay on this server, so copy them somewhere else now
                    and then: a backup on the same disk is lost with it. A dump
                    holds the data itself, so downloading one needs your
                    password and is recorded. To load one back, press{' '}
                    <strong>Restore</strong>: it goes into a new database, and
                    nothing that exists is changed.
                </p>
            </Card>

            {manage && (
                <Card
                    title="Delete this server"
                    description={`Removes the server and permanently deletes every database on it (${server.databases_count}) with all their data. This cannot be undone, and it is refused while an app still uses one of its databases.`}
                    actions={
                        <Button
                            variant="error"
                            onClick={() => setDeleting(true)}
                        >
                            <Trash2 className="size-4" aria-hidden />
                            Delete server
                        </Button>
                    }
                />
            )}

            <AddDatabaseModal
                slug={server.slug}
                open={adding}
                onClose={() => setAdding(false)}
            />
            <CredentialsModal
                credentials={credentials}
                onClose={() => setCredentials(null)}
            />
            <RestoreBackupModal
                file={restoringFile}
                error={restoreError}
                busy={busy}
                onSubmit={restoreBackup}
                onClose={() => setRestoringFile(null)}
            />

            <ConfirmModal
                open={removingBackup !== null}
                title="Delete this backup?"
                confirmLabel="Delete backup"
                onConfirm={deleteBackup}
                onClose={() => setRemovingBackup(null)}
            >
                <code className="break-all">{removingBackup?.name}</code> is
                deleted. This cannot be undone.
            </ConfirmModal>
            <ConfirmModal
                open={rotating !== null}
                title="Change the password?"
                confirmLabel="Change password"
                loading={busy}
                onConfirm={rotate}
                onClose={() => setRotating(null)}
            >
                A new password is set for <code>{rotating?.username}</code>.
                Apps linked to this database keep using the old one, and stop
                working, until they are deployed again.
            </ConfirmModal>
            <TypedConfirmModal
                open={dropping !== null}
                title="Delete this database?"
                expected={dropping?.name ?? ''}
                confirmLabel="Delete database"
                loading={busy}
                error={errors.confirm}
                onConfirm={drop}
                onClose={() => setDropping(null)}
            >
                <p>
                    <code>{dropping?.name}</code> and all its data are deleted,
                    together with its user. This cannot be undone.
                </p>
                <p className="text-base-content/70">
                    If an app is linked to it, remove the link first.
                </p>
            </TypedConfirmModal>
            <TypedConfirmModal
                open={deleting}
                title="Delete this server?"
                expected={server.slug}
                confirmLabel="Delete server"
                loading={busy}
                error={errors.confirm}
                onConfirm={deleteServer}
                onClose={() => setDeleting(false)}
            >
                <p>
                    <strong>{server.name}</strong> is removed, and so is the
                    data of all {server.databases_count} of its databases. This
                    cannot be undone.
                </p>
            </TypedConfirmModal>
            {confirmed.modal}
        </AppLayout>
    );
}
