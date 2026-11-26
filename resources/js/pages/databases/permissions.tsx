import { Link } from '@inertiajs/react';
import { ArrowLeft, Check, Minus, Pencil, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import DatabaseServerController from '@/actions/App/Http/Controllers/DatabaseServerController';
import {
    PermissionEditor,
    usePrivilegeGroups,
    usePrivilegeLabels,
} from '@/components/databases/permission-editor';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/lib/permissions';
import { cn } from '@/lib/utils';
import type { DatabaseServer, ServerPermissions } from '@/types';

type Props = {
    server: DatabaseServer;
    permissions: ServerPermissions;
    /** The managed database whose user this page is about. */
    user_id: number;
};

/**
 * One database user's permissions, grouped and readable. People who may change them press Edit to switch the
 * same view into an editor, and save from there.
 */
export default function Permissions({ server, permissions, user_id }: Props) {
    const { t } = useT();
    const can = useCan();
    const labels = usePrivilegeLabels();
    const [editing, setEditing] = useState(false);
    const row = permissions.users.find((user) => user.id === user_id);
    const keys = row
        ? permissions.privileges.filter((key) => key in row.granted)
        : [];
    const groups = usePrivilegeGroups(keys);

    if (!row) return null;

    const wholeServer = row.scope === 'server';
    const mayEdit = can('databases.manage') && row.editable_keys.length > 0;
    const postgres = permissions.privileges.includes('login');

    return (
        <AppLayout title={t('Permissions of :user', { user: row.user })}>
            <PageHeader
                title={t('Permissions of :user', { user: row.user })}
                description={
                    wholeServer
                        ? t('The whole server')
                        : t('Only the database :name', { name: row.database })
                }
                actions={
                    <>
                        <Link
                            href={DatabaseServerController.show(server.slug)}
                            className="btn btn-text"
                        >
                            <ArrowLeft className="size-4" aria-hidden />
                            {server.name}
                        </Link>
                        {mayEdit && !editing && (
                            <Button onClick={() => setEditing(true)}>
                                <Pencil className="size-4" aria-hidden />
                                {t('Edit')}
                            </Button>
                        )}
                    </>
                }
            />

            {editing ? (
                <PermissionEditor
                    key={row.id}
                    serverSlug={server.slug}
                    row={row}
                    presets={permissions.presets}
                    onClose={() => setEditing(false)}
                />
            ) : (
                <Card
                    title={t('User permissions')}
                    actions={
                        row.custom ? (
                            <span className="badge-soft badge badge-info">
                                {t('Custom')}
                            </span>
                        ) : undefined
                    }
                >
                    {wholeServer && (
                        <div
                            role="note"
                            className="flex items-start gap-3 rounded-field border border-warning bg-warning/10 p-3 text-sm"
                        >
                            <ShieldAlert
                                className="mt-0.5 size-5 shrink-0 text-warning"
                                aria-hidden
                            />
                            <span>
                                {t(
                                    'This user can create and drop databases on this server by itself, which multi-database and tenancy packages need.',
                                )}
                            </span>
                        </div>
                    )}
                    <div className="grid gap-6 md:grid-cols-2">
                        {groups.map((group) => (
                            <section
                                key={group.id}
                                aria-label={group.legend}
                                className="min-w-0 space-y-2"
                            >
                                <h3 className="text-sm font-medium">
                                    {group.legend}
                                </h3>
                                <ul className="space-y-1.5">
                                    {group.keys.map((key) => {
                                        const allowed = row.granted[key];

                                        return (
                                            <li
                                                key={key}
                                                className={cn(
                                                    'flex items-center gap-2 text-sm',
                                                    !allowed &&
                                                        'text-base-content/60',
                                                )}
                                            >
                                                {allowed ? (
                                                    <Check
                                                        className={cn(
                                                            'size-4 shrink-0',
                                                            wholeServer
                                                                ? 'text-warning'
                                                                : 'text-success',
                                                        )}
                                                        aria-hidden
                                                    />
                                                ) : (
                                                    <Minus
                                                        className="size-4 shrink-0"
                                                        aria-hidden
                                                    />
                                                )}
                                                <span>
                                                    {labels[key] ?? key}
                                                </span>
                                                <span className="sr-only">
                                                    {allowed
                                                        ? t('Allowed')
                                                        : t('Not allowed')}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </section>
                        ))}
                    </div>
                    {postgres && !mayEdit && (
                        <p className="text-sm text-base-content/70">
                            {t(
                                'On PostgreSQL an ordinary user keeps what it needs: it logs in and owns its database, so there is nothing to change.',
                            )}
                        </p>
                    )}
                </Card>
            )}
        </AppLayout>
    );
}
