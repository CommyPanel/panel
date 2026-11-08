import { Link, router, usePage } from '@inertiajs/react';
import { Lock, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import RoleController from '@/actions/App/Http/Controllers/RoleController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/lib/permissions';

type Row = {
    id: number;
    name: string;
    permissions: number;
    users: number;
    locked: boolean;
    can_edit: boolean;
};

type Props = { roles: Row[]; total_permissions: number };

export default function Index({ roles, total_permissions }: Props) {
    const { t } = useT();
    const can = useCan();
    const { errors } = usePage().props;
    const [deleting, setDeleting] = useState<Row | null>(null);
    const [busy, setBusy] = useState(false);

    const remove = () => {
        if (!deleting) return;
        router.delete(RoleController.destroy(deleting.id), {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setDeleting(null);
            },
        });
    };

    return (
        <AppLayout title={t('Roles')}>
            <PageHeader
                title={t('Roles')}
                description={t(
                    'A role is a set of permissions. Give a role to a user to decide what they may do.',
                )}
                actions={
                    can('roles.manage') && (
                        <Link
                            href={RoleController.create()}
                            className="btn btn-primary"
                        >
                            <Plus className="size-4" aria-hidden />
                            {t('New role')}
                        </Link>
                    )
                }
            />

            {errors.role && <Alert variant="error">{errors.role}</Alert>}

            <Card flush>
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>{t('Role')}</th>
                                <th>{t('Permissions')}</th>
                                <th>{t('Users')}</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {roles.map((role) => (
                                <tr key={role.id}>
                                    <td className="font-medium">
                                        {role.name}
                                        {role.locked && (
                                            <span
                                                className="ml-2 inline-flex items-center gap-1 text-xs font-normal text-base-content/60"
                                                title={t(
                                                    'Always has every permission, so it cannot be edited or deleted.',
                                                )}
                                            >
                                                <Lock
                                                    className="size-3.5"
                                                    aria-hidden
                                                />
                                                {t('built in')}
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        {t(':count of :total', {
                                            count: role.permissions,
                                            total: total_permissions,
                                        })}
                                    </td>
                                    <td>{role.users}</td>
                                    <td className="text-right whitespace-nowrap">
                                        {role.can_edit &&
                                            can('roles.manage') && (
                                                <div className="flex justify-end gap-1">
                                                    <Link
                                                        href={RoleController.edit(
                                                            role.id,
                                                        )}
                                                        className="btn btn-soft btn-sm"
                                                    >
                                                        <Pencil
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        {t('Edit')}
                                                    </Link>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={t(
                                                            'Delete :name',
                                                            {
                                                                name: role.name,
                                                            },
                                                        )}
                                                        disabled={
                                                            role.users > 0
                                                        }
                                                        title={
                                                            role.users > 0
                                                                ? t(
                                                                      'Give its users another role first',
                                                                  )
                                                                : undefined
                                                        }
                                                        onClick={() =>
                                                            setDeleting(role)
                                                        }
                                                    >
                                                        <Trash2
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                    </Button>
                                                </div>
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>

            <ConfirmModal
                open={deleting !== null}
                title={t('Delete the role :name?', {
                    name: deleting?.name ?? '',
                })}
                confirmLabel={t('Delete role')}
                loading={busy}
                onConfirm={remove}
                onClose={() => setDeleting(null)}
            >
                {t(
                    'No one has this role, so nobody loses access. This cannot be undone.',
                )}
            </ConfirmModal>
        </AppLayout>
    );
}
