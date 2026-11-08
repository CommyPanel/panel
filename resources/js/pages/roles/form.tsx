import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import RoleController from '@/actions/App/Http/Controllers/RoleController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';

type Permission = {
    name: string;
    label: string;
    description: string;
    grantable: boolean;
};

type Props = {
    role: {
        id: number;
        name: string;
        permissions: string[];
        users: number;
    } | null;
    groups: { group: string; permissions: Permission[] }[];
};

export default function Form({ role, groups }: Props) {
    const { t, tn } = useT();
    const editing = role !== null;
    const form = useForm<{ name: string; permissions: string[] }>({
        name: role?.name ?? '',
        permissions: role?.permissions ?? [],
    });

    const toggle = (name: string, on: boolean) =>
        form.setData(
            'permissions',
            on
                ? [...form.data.permissions, name]
                : form.data.permissions.filter((p) => p !== name),
        );

    const toggleGroup = (permissions: Permission[], on: boolean) => {
        const names = permissions.filter((p) => p.grantable).map((p) => p.name);
        const rest = form.data.permissions.filter((p) => !names.includes(p));
        form.setData('permissions', on ? [...rest, ...names] : rest);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing) form.put(RoleController.update(role.id).url);
        else form.post(RoleController.store().url);
    };

    return (
        <AppLayout
            title={
                editing ? t('Edit :name', { name: role.name }) : t('New role')
            }
        >
            <PageHeader
                title={
                    editing
                        ? t('Edit :name', { name: role.name })
                        : t('New role')
                }
                description={
                    editing && role.users > 0
                        ? tn(
                              'one::count user has this role; changes apply to them at once.|other::count users have this role; changes apply to them at once.',
                              role.users,
                          )
                        : t('Choose what people with this role may do.')
                }
            />
            <form onSubmit={submit} className="space-y-6">
                <Card title={t('Name')}>
                    <Input
                        label={t('Role name')}
                        required
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />
                </Card>

                {form.errors.permissions && (
                    <Alert variant="error">{form.errors.permissions}</Alert>
                )}

                {groups.map(({ group, permissions }) => {
                    const grantable = permissions.filter((p) => p.grantable);
                    const all =
                        grantable.length > 0 &&
                        grantable.every((p) =>
                            form.data.permissions.includes(p.name),
                        );

                    return (
                        <Card
                            key={group}
                            title={group}
                            actions={
                                grantable.length > 0 && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            toggleGroup(permissions, !all)
                                        }
                                    >
                                        {all ? t('Clear all') : t('Select all')}
                                    </Button>
                                )
                            }
                        >
                            <ul className="space-y-3">
                                {permissions.map((permission) => (
                                    <li key={permission.name}>
                                        <label className="flex items-start gap-3">
                                            <input
                                                type="checkbox"
                                                className="checkbox mt-0.5 checkbox-primary"
                                                checked={form.data.permissions.includes(
                                                    permission.name,
                                                )}
                                                disabled={!permission.grantable}
                                                onChange={(e) =>
                                                    toggle(
                                                        permission.name,
                                                        e.target.checked,
                                                    )
                                                }
                                            />
                                            <span className="min-w-0 text-sm">
                                                <span className="font-medium">
                                                    {permission.label}
                                                </span>{' '}
                                                <code className="text-xs text-base-content/60">
                                                    {permission.name}
                                                </code>
                                                <span className="block text-base-content/70">
                                                    {permission.description}
                                                </span>
                                                {!permission.grantable && (
                                                    <span className="block text-xs text-base-content/60">
                                                        {t(
                                                            'You do not have this permission yourself, so you cannot grant it.',
                                                        )}
                                                    </span>
                                                )}
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    );
                })}

                <div className="flex gap-2">
                    <Button type="submit" loading={form.processing}>
                        {editing ? t('Save role') : t('Create role')}
                    </Button>
                    <Link
                        href={RoleController.index()}
                        className="btn btn-text"
                    >
                        {t('Cancel')}
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
