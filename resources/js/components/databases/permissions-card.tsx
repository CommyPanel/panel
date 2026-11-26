import { Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import ManagedDatabaseController from '@/actions/App/Http/Controllers/ManagedDatabaseController';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';
import type { ServerPermissions } from '@/types';

/**
 * Who may do what on this server, in a short list: each user opens a page of its own with the details
 * (and, for people who may change them, an edit mode).
 */
export function PermissionsCard({
    permissions,
    serverSlug,
}: {
    permissions: ServerPermissions;
    serverSlug: string;
}) {
    const { t } = useT();

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
                                    <th scope="col">{t('Access')}</th>
                                    <th scope="col">
                                        <span className="sr-only">
                                            {t('User permissions')}
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {permissions.users.map((row) => {
                                    const total =
                                        row.editable_keys.length > 0
                                            ? row.editable_keys.length
                                            : Object.values(row.granted).filter(
                                                  Boolean,
                                              ).length;
                                    const count =
                                        row.editable_keys.length > 0
                                            ? row.editable_keys.filter(
                                                  (key) => row.granted[key],
                                              ).length
                                            : total;

                                    return (
                                        <tr
                                            key={row.id}
                                            className={cn(
                                                row.scope === 'server' &&
                                                    'bg-warning/10',
                                            )}
                                        >
                                            <th
                                                scope="row"
                                                className="font-mono"
                                            >
                                                {row.user}
                                            </th>
                                            <td className="min-w-40">
                                                {row.scope === 'server' ? (
                                                    <span className="badge-soft badge badge-warning">
                                                        {t('The whole server')}
                                                    </span>
                                                ) : (
                                                    t(
                                                        'Only the database :name',
                                                        { name: row.database },
                                                    )
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap">
                                                {t(':count of :total', {
                                                    count: String(count),
                                                    total: String(total),
                                                })}
                                                {row.custom && (
                                                    <span className="badge-soft ml-2 badge badge-sm badge-info">
                                                        {t('Custom')}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="text-right">
                                                <Link
                                                    href={ManagedDatabaseController.permissions(
                                                        {
                                                            server: serverSlug,
                                                            database: row.id,
                                                        },
                                                    )}
                                                    className="btn btn-soft btn-sm"
                                                    aria-label={t(
                                                        'Permissions of :user',
                                                        { user: row.user },
                                                    )}
                                                >
                                                    {t('User permissions')}
                                                </Link>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </Card>
    );
}
