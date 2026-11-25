import { Check, Minus, Pencil, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import {
    PermissionEditor,
    usePrivilegeLabels,
} from '@/components/databases/permission-editor';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import { useCan } from '@/lib/permissions';
import { cn } from '@/lib/utils';
import type { ServerPermissions } from '@/types';

/**
 * What the users of this server may do, as CommyPanel grants it: an ordinary user holds everything on its
 * own database and nothing else; the user of a server made for one app may also manage the whole server.
 */
export function PermissionsCard({
    permissions,
    serverSlug,
}: {
    permissions: ServerPermissions;
    serverSlug: string;
}) {
    const { t } = useT();
    const can = useCan();
    const labels = usePrivilegeLabels();
    const [editing, setEditing] = useState<number | null>(null);
    const mayEdit = can('databases.manage');
    const editingRow = permissions.users.find((row) => row.id === editing);
    const postgres = permissions.privileges.includes('login');

    return (
        <Card
            title={t('Permissions')}
            description={t(
                'What each database user may do. An ordinary user can use its own database and nothing else on this server.',
            )}
        >
            {permissions.users.length === 0 ? (
                <p className="text-sm text-base-content/70">
                    {t('No users yet. Each database you add gets one.')}
                </p>
            ) : (
                <div className="space-y-4">
                    {permissions.whole_server && (
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
                    <div className="overflow-x-auto rounded-field border border-base-300">
                        <table className="table">
                            <caption className="sr-only">
                                {t('Permissions of the database users')}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">{t('User')}</th>
                                    <th scope="col">{t('Applies to')}</th>
                                    {permissions.privileges.map((key) => (
                                        <th
                                            key={key}
                                            scope="col"
                                            className="text-center"
                                        >
                                            {labels[key] ?? key}
                                        </th>
                                    ))}
                                    {mayEdit && (
                                        <th scope="col">
                                            <span className="sr-only">
                                                {t('Change')}
                                            </span>
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {permissions.users.map((row) => (
                                    <tr
                                        key={row.user}
                                        className={cn(
                                            row.scope === 'server' &&
                                                'bg-warning/10',
                                            editing === row.id &&
                                                'outline outline-primary',
                                        )}
                                    >
                                        <th scope="row" className="font-mono">
                                            {row.user}
                                        </th>
                                        <td className="min-w-40">
                                            {row.scope === 'server' ? (
                                                <span className="badge-soft badge badge-warning">
                                                    {t('The whole server')}
                                                </span>
                                            ) : (
                                                <span>
                                                    {t(
                                                        'Only the database :name',
                                                        { name: row.database },
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        {permissions.privileges.map((key) => (
                                            <td
                                                key={key}
                                                className="text-center"
                                            >
                                                {row.granted[key] ? (
                                                    <span
                                                        className={cn(
                                                            'inline-flex',
                                                            row.scope ===
                                                                'server'
                                                                ? 'text-warning'
                                                                : 'text-success',
                                                        )}
                                                    >
                                                        <Check
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        <span className="sr-only">
                                                            {t('Allowed')}
                                                        </span>
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex text-base-content/60">
                                                        <Minus
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        <span className="sr-only">
                                                            {t('Not allowed')}
                                                        </span>
                                                    </span>
                                                )}
                                            </td>
                                        ))}
                                        {mayEdit && (
                                            <td className="text-right">
                                                {row.editable_keys.length >
                                                    0 && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={t(
                                                            'Change the permissions of :user',
                                                            { user: row.user },
                                                        )}
                                                        onClick={() =>
                                                            setEditing(
                                                                editing ===
                                                                    row.id
                                                                    ? null
                                                                    : row.id,
                                                            )
                                                        }
                                                    >
                                                        <Pencil
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        {t('Edit')}
                                                    </Button>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {editingRow && (
                        <PermissionEditor
                            key={editingRow.id}
                            serverSlug={serverSlug}
                            row={editingRow}
                            presets={permissions.presets}
                            onClose={() => setEditing(null)}
                        />
                    )}
                    {postgres && mayEdit && (
                        <p className="text-sm text-base-content/70">
                            {t(
                                'On PostgreSQL an ordinary user keeps what it needs: it logs in and owns its database, so there is nothing to change.',
                            )}
                        </p>
                    )}
                </div>
            )}
        </Card>
    );
}
