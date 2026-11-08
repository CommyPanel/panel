import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LanguageSelect } from '@/components/language-select';
import { useT } from '@/hooks/use-t';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';

type Role = {
    id: number;
    name: string;
    permissions: number;
    assignable: boolean;
};

type Props = {
    user: {
        id: number;
        name: string;
        email: string;
        locale: string;
        role_id: number | null;
        is_you: boolean;
    } | null;
    roles: Role[];
};

export default function Form({ user, roles }: Props) {
    const { t, tn, locale } = useT();
    const editing = user !== null;
    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
        password_confirmation: '',
        role: user?.role_id ? String(user.role_id) : '',
        // A new person starts with the language of whoever adds them, and can change it.
        locale: user?.locale ?? locale,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (editing) {
            form.put(UserController.update(user.id).url, {
                onFinish: () => form.reset('password', 'password_confirmation'),
            });
        } else {
            form.post(UserController.store().url, {
                onFinish: () => form.reset('password', 'password_confirmation'),
            });
        }
    };

    return (
        <AppLayout
            title={
                editing ? t('Edit :name', { name: user.name }) : t('New user')
            }
        >
            <PageHeader
                title={
                    editing
                        ? t('Edit :name', { name: user.name })
                        : t('New user')
                }
                description={
                    editing
                        ? t(
                              'Changing the password signs this user out everywhere.',
                          )
                        : t(
                              'They can sign in with this email and password right away.',
                          )
                }
            />
            <form onSubmit={submit} className="space-y-6">
                <Card title={t('Account')}>
                    <Input
                        label={t('Name')}
                        autoComplete="off"
                        required
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />
                    <Input
                        label={t('Email')}
                        type="email"
                        autoComplete="off"
                        required
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        error={form.errors.email}
                    />
                    <LanguageSelect
                        value={form.data.locale}
                        onChange={(code) => form.setData('locale', code)}
                        error={form.errors.locale}
                        hint={
                            editing
                                ? undefined
                                : t(
                                      'The language of their panel. They can change it themselves.',
                                  )
                        }
                    />
                    <Input
                        label={editing ? t('New password') : t('Password')}
                        type="password"
                        autoComplete="new-password"
                        required={!editing}
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData('password', e.target.value)
                        }
                        error={form.errors.password}
                        hint={
                            editing
                                ? t('Leave empty to keep the current one.')
                                : undefined
                        }
                    />
                    <Input
                        label={t('Confirm password')}
                        type="password"
                        autoComplete="new-password"
                        required={!editing || form.data.password !== ''}
                        value={form.data.password_confirmation}
                        onChange={(e) =>
                            form.setData(
                                'password_confirmation',
                                e.target.value,
                            )
                        }
                        error={form.errors.password_confirmation}
                    />
                </Card>

                <Card
                    title={t('Role')}
                    description={t(
                        'What this user may do. Manage roles and their permissions on the Roles page.',
                    )}
                >
                    {user?.is_you && (
                        <Alert variant="info">
                            {t('You cannot change your own role.')}
                        </Alert>
                    )}
                    <Select
                        label={t('Role')}
                        value={form.data.role}
                        disabled={user?.is_you}
                        onChange={(e) => form.setData('role', e.target.value)}
                        error={form.errors.role}
                        hint={t(
                            'Without a role the user can sign in and change their own settings, nothing else. You can only give roles that grant no more than you have yourself.',
                        )}
                    >
                        <option value="">{t('No role')}</option>
                        {roles.map((role) => (
                            <option
                                key={role.id}
                                value={role.id}
                                disabled={
                                    !role.assignable &&
                                    String(role.id) !== form.data.role
                                }
                            >
                                {role.assignable
                                    ? tn(
                                          'one:::name (:count permission)|other:::name (:count permissions)',
                                          role.permissions,
                                          { name: role.name },
                                      )
                                    : tn(
                                          'one:::name (:count permission) - more than you can give|other:::name (:count permissions) - more than you can give',
                                          role.permissions,
                                          { name: role.name },
                                      )}
                            </option>
                        ))}
                    </Select>
                </Card>

                <div className="flex gap-2">
                    <Button type="submit" loading={form.processing}>
                        {editing ? t('Save user') : t('Create user')}
                    </Button>
                    <Link
                        href={UserController.index()}
                        className="btn btn-text"
                    >
                        {t('Cancel')}
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
