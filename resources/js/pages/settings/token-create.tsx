import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ApiTokenController from '@/actions/App/Http/Controllers/ApiTokenController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import AppLayout from '@/layouts/app-layout';

type Props = {
    abilities: { key: string; label: string; description: string }[];
    apps: { slug: string; name: string }[];
    default_days: number;
};

export default function TokenCreate({ abilities, apps, default_days }: Props) {
    const { t } = useT();
    const lifetimes = [
        { value: '30', label: t('30 days') },
        { value: '90', label: t('90 days') },
        { value: '180', label: t('6 months') },
        { value: '365', label: t('1 year') },
        { value: 'never', label: t('Never expires') },
    ];
    const confirmed = useConfirmedAction();
    const form = useForm<{
        name: string;
        abilities: string[];
        apps: string[];
        days: string;
    }>({ name: '', abilities: [], apps: [], days: String(default_days) });
    const [limited, setLimited] = useState(false);

    const toggle = (field: 'abilities' | 'apps', value: string) =>
        form.setData(
            field,
            form.data[field].includes(value)
                ? form.data[field].filter((v) => v !== value)
                : [...form.data[field], value],
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void confirmed.run(() => {
            form.transform((data) => ({
                ...data,
                apps: limited ? data.apps : [],
            }));
            form.post(ApiTokenController.store().url);
        });
    };

    return (
        <AppLayout title={t('New API token')}>
            <PageHeader
                title={t('New API token')}
                description={t(
                    'One token for one script or pipeline, so you can see what it does and revoke it on its own.',
                )}
                actions={
                    <Link
                        href={ApiTokenController.index()}
                        className="btn btn-text"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        {t('All tokens')}
                    </Link>
                }
            />

            {abilities.length === 0 && (
                <Alert variant="info">
                    {t(
                        'Your account may not view or deploy apps, so there is nothing a token could do for you. Ask an administrator for those permissions.',
                    )}
                </Alert>
            )}

            <form onSubmit={submit} className="space-y-6">
                <Card title={t('1. Name')}>
                    <Input
                        label={t('Name')}
                        placeholder={t('GitHub Actions, deploy shop')}
                        required
                        autoFocus
                        autoComplete="off"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                        hint={t(
                            'So you know which script it belongs to when you look at the audit log.',
                        )}
                    />
                </Card>

                <Card
                    title={t('2. What it may do')}
                    description={t(
                        'A token can do only what you may do, now and later: if you lose a permission, it loses it too.',
                    )}
                >
                    <fieldset className="space-y-3">
                        <legend className="sr-only">
                            {t('What it may do')}
                        </legend>
                        {abilities.map((ability) => (
                            <label
                                key={ability.key}
                                className="flex cursor-pointer items-start gap-3"
                            >
                                <input
                                    type="checkbox"
                                    className="checkbox mt-0.5"
                                    checked={form.data.abilities.includes(
                                        ability.key,
                                    )}
                                    onChange={() =>
                                        toggle('abilities', ability.key)
                                    }
                                />
                                <span className="text-sm">
                                    <span className="font-medium">
                                        {ability.label}
                                    </span>
                                    <br />
                                    <span className="text-base-content/70">
                                        {ability.description}
                                    </span>
                                </span>
                            </label>
                        ))}
                        {(form.errors.abilities ||
                            form.errors['abilities.0']) && (
                            <p className="text-sm text-error">
                                {form.errors.abilities ??
                                    form.errors['abilities.0']}
                            </p>
                        )}
                    </fieldset>
                </Card>

                <Card
                    title={t('3. Which apps')}
                    description={t(
                        'A pipeline that deploys one app should not be able to touch the others.',
                    )}
                >
                    <fieldset className="space-y-2">
                        <legend className="sr-only">{t('Which apps')}</legend>
                        <label className="flex cursor-pointer items-center gap-3 text-sm">
                            <input
                                type="radio"
                                className="radio"
                                checked={!limited}
                                onChange={() => setLimited(false)}
                            />
                            {t('All apps I may use')}
                        </label>
                        <label className="flex cursor-pointer items-center gap-3 text-sm">
                            <input
                                type="radio"
                                className="radio"
                                checked={limited}
                                onChange={() => setLimited(true)}
                            />
                            {t('Only these apps (safer)')}
                        </label>
                        {limited && (
                            <ul className="max-h-60 space-y-1 overflow-y-auto rounded-field border border-base-300 p-3">
                                {apps.length === 0 && (
                                    <li className="text-sm text-base-content/70">
                                        {t('There are no apps yet.')}
                                    </li>
                                )}
                                {apps.map((app) => (
                                    <li key={app.slug}>
                                        <label className="flex cursor-pointer items-center gap-3 text-sm">
                                            <input
                                                type="checkbox"
                                                className="checkbox checkbox-sm"
                                                checked={form.data.apps.includes(
                                                    app.slug,
                                                )}
                                                onChange={() =>
                                                    toggle('apps', app.slug)
                                                }
                                            />
                                            {app.name}{' '}
                                            <code className="text-xs text-base-content/60">
                                                {app.slug}
                                            </code>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </fieldset>
                </Card>

                <Card title={t('4. How long it lasts')}>
                    <Select
                        label={t('Valid for')}
                        value={form.data.days}
                        onChange={(e) => form.setData('days', e.target.value)}
                        error={form.errors.days}
                        hint={
                            form.data.days === 'never'
                                ? undefined
                                : t(
                                      'When it runs out it stops working and you make a new one.',
                                  )
                        }
                    >
                        {lifetimes.map((l) => (
                            <option key={l.value} value={l.value}>
                                {l.label}
                            </option>
                        ))}
                    </Select>
                    {form.data.days === 'never' && (
                        <Alert variant="error" dashed>
                            <div className="space-y-1">
                                <p className="font-medium">
                                    {t('This token never expires.')}
                                </p>
                                <p className="text-sm">
                                    {t(
                                        'It keeps working until you revoke it, or until its owner is deleted. It also stops if its owner loses the permissions it uses. Anyone who gets hold of it can use it for as long as that lasts, so treat it like a password. Rotating it regularly is strongly advised: make a new token, switch your pipeline over, then revoke the old one. A lifetime of a year or less is safer.',
                                    )}
                                </p>
                            </div>
                        </Alert>
                    )}
                </Card>

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={
                            form.data.abilities.length === 0 ||
                            (limited && form.data.apps.length === 0)
                        }
                    >
                        {t('Create token')}
                    </Button>
                    <Link
                        href={ApiTokenController.index()}
                        className="btn btn-text"
                    >
                        {t('Cancel')}
                    </Link>
                </div>
            </form>
            {confirmed.modal}
        </AppLayout>
    );
}
