import { router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ApiTokenController from '@/actions/App/Http/Controllers/ApiTokenController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { Select } from '@/components/ui/select';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import AppLayout from '@/layouts/app-layout';
import { formatDate, timeAgo } from '@/lib/format';

type Token = {
    id: number;
    name: string;
    abilities: string[];
    apps: string[];
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string | null;
    expired: boolean;
};

type Props = {
    tokens: Token[];
    abilities: { key: string; label: string; description: string }[];
    apps: { slug: string; name: string }[];
    new_token: { name: string; token: string } | null;
    default_days: number;
    max_days: number;
    api_url: string;
};

const LIFETIMES = [
    { days: 30, label: '30 days' },
    { days: 90, label: '90 days' },
    { days: 180, label: '6 months' },
    { days: 365, label: '1 year' },
];

export default function Tokens({
    tokens,
    abilities,
    apps,
    new_token,
    default_days,
    api_url,
}: Props) {
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();
    const [creating, setCreating] = useState(false);
    const [revoking, setRevoking] = useState<Token | null>(null);

    const revoke = () => {
        if (!revoking) return;

        void confirmed.run(() =>
            router.delete(ApiTokenController.destroy(revoking.id), {
                preserveScroll: true,
                onFinish: () => setRevoking(null),
            }),
        );
    };

    return (
        <AppLayout title="API tokens">
            <PageHeader
                title="API tokens"
                description="Let a script or a CI pipeline start deployments and read how they went, without your password."
                actions={
                    abilities.length > 0 && (
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="size-4" aria-hidden />
                            New token
                        </Button>
                    )
                }
            />

            {new_token && <NewToken token={new_token} apiUrl={api_url} />}

            {abilities.length === 0 && (
                <Alert variant="info">
                    Your account may not view or deploy apps, so there is
                    nothing a token could do for you. Ask an administrator for
                    those permissions.
                </Alert>
            )}

            <Card
                title="Your tokens"
                description="A token can do only what you may do, now and later: if you lose a permission, your tokens lose it too."
                flush={tokens.length > 0}
            >
                {tokens.length === 0 ? (
                    <p className="text-sm text-base-content/70">
                        You have no tokens. Make one for each script that needs
                        access, so you can revoke it on its own.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>May</th>
                                    <th>Apps</th>
                                    <th>Last used</th>
                                    <th>Expires</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {tokens.map((token) => (
                                    <tr key={token.id}>
                                        <td className="font-medium">
                                            {token.name}
                                        </td>
                                        <td className="text-sm">
                                            {token.abilities.join(', ') || '-'}
                                        </td>
                                        <td className="text-sm">
                                            {token.apps.length === 0 ? (
                                                'All apps'
                                            ) : (
                                                <code>
                                                    {token.apps.join(', ')}
                                                </code>
                                            )}
                                        </td>
                                        <td
                                            className="text-sm whitespace-nowrap"
                                            title={
                                                token.last_used_at
                                                    ? formatDate(
                                                          token.last_used_at,
                                                      )
                                                    : undefined
                                            }
                                        >
                                            {token.last_used_at
                                                ? timeAgo(token.last_used_at)
                                                : 'Never'}
                                        </td>
                                        <td className="text-sm whitespace-nowrap">
                                            {token.expired ? (
                                                <span className="badge-soft badge badge-error">
                                                    Expired
                                                </span>
                                            ) : token.expires_at ? (
                                                formatDate(token.expires_at)
                                            ) : (
                                                'Never'
                                            )}
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setRevoking(token)
                                                }
                                            >
                                                <Trash2
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                                Revoke
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>

            <Card
                title="Using a token"
                description="Send it as a bearer token. Every call needs https."
            >
                <pre className="overflow-x-auto rounded-field bg-base-200 p-3 text-xs">
                    {`# start a deployment (answers 202 with its number)
curl -X POST ${api_url}/apps/APP/deployments \\
  -H "Authorization: Bearer $COMMYPANEL_TOKEN"

# follow it until "finished" is true
curl ${api_url}/apps/APP/deployments/NUMBER \\
  -H "Authorization: Bearer $COMMYPANEL_TOKEN"`}
                </pre>
                <p className="text-sm text-base-content/70">
                    Keep the token in your CI&apos;s secret store, never in a
                    repository. If one leaks, revoke it here; it stops working
                    at once. Failed attempts are limited, and every deployment a
                    token starts is recorded in the audit log with its name.
                </p>
            </Card>

            {errors.name && !creating && (
                <Alert variant="error">{errors.name}</Alert>
            )}

            <CreateModal
                open={creating}
                onClose={() => setCreating(false)}
                abilities={abilities}
                apps={apps}
                defaultDays={default_days}
                run={confirmed.run}
            />

            <ConfirmModal
                open={revoking !== null}
                title="Revoke this token?"
                confirmLabel="Revoke token"
                onConfirm={revoke}
                onClose={() => setRevoking(null)}
            >
                <strong>{revoking?.name}</strong> stops working at once. A
                script that still uses it will get 401 errors until it has a new
                token.
            </ConfirmModal>
            {confirmed.modal}
        </AppLayout>
    );
}

function NewToken({
    token,
    apiUrl,
}: {
    token: { name: string; token: string };
    apiUrl: string;
}) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(token.token);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // The text is selectable; it can be copied by hand.
        }
    };

    return (
        <Alert variant="success">
            <div className="w-full space-y-3">
                <p className="font-medium">
                    The token {token.name} was made. Copy it now: it is shown
                    only this once and cannot be shown again.
                </p>
                <div className="flex flex-wrap items-center gap-2">
                    <code className="min-w-0 flex-1 rounded-field bg-base-100 px-3 py-2 text-sm break-all select-all">
                        {token.token}
                    </code>
                    <Button
                        variant="soft"
                        size="sm"
                        onClick={() => void copy()}
                    >
                        {copied ? (
                            <Check className="size-4" aria-hidden />
                        ) : (
                            <Copy className="size-4" aria-hidden />
                        )}
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                </div>
                <p className="text-sm">
                    Use it as <code>Authorization: Bearer …</code> against{' '}
                    <code>{apiUrl}</code>.
                </p>
            </div>
        </Alert>
    );
}

function CreateModal({
    open,
    onClose,
    abilities,
    apps,
    defaultDays,
    run,
}: {
    open: boolean;
    onClose: () => void;
    abilities: Props['abilities'];
    apps: Props['apps'];
    defaultDays: number;
    run: (action: () => void) => Promise<void>;
}) {
    const form = useForm<{
        name: string;
        abilities: string[];
        apps: string[];
        days: number;
    }>({ name: '', abilities: [], apps: [], days: defaultDays });
    const [limited, setLimited] = useState(false);

    const toggle = (field: 'abilities' | 'apps', value: string) =>
        form.setData(
            field,
            form.data[field].includes(value)
                ? form.data[field].filter((v) => v !== value)
                : [...form.data[field], value],
        );

    const close = () => {
        form.reset();
        form.clearErrors();
        setLimited(false);
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void run(() => {
            form.transform((data) => ({
                ...data,
                apps: limited ? data.apps : [],
            }));
            form.post(ApiTokenController.store().url, {
                preserveScroll: true,
                onSuccess: close,
            });
        });
    };

    return (
        <Modal open={open} onClose={close} title="New API token">
            <form onSubmit={submit} className="space-y-5">
                <Input
                    label="Name"
                    placeholder="GitHub Actions, deploy shop"
                    required
                    autoFocus
                    autoComplete="off"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={form.errors.name}
                    hint="So you know which script it belongs to when you look at the audit log."
                />

                <fieldset className="space-y-2">
                    <legend className="mb-1 label-text">What it may do</legend>
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
                    {(form.errors.abilities || form.errors['abilities.0']) && (
                        <p className="text-sm text-error">
                            {form.errors.abilities ??
                                form.errors['abilities.0']}
                        </p>
                    )}
                </fieldset>

                <fieldset className="space-y-2">
                    <legend className="mb-1 label-text">Which apps</legend>
                    <label className="flex cursor-pointer items-center gap-3 text-sm">
                        <input
                            type="radio"
                            className="radio"
                            checked={!limited}
                            onChange={() => setLimited(false)}
                        />
                        All apps I may use
                    </label>
                    <label className="flex cursor-pointer items-center gap-3 text-sm">
                        <input
                            type="radio"
                            className="radio"
                            checked={limited}
                            onChange={() => setLimited(true)}
                        />
                        Only these apps (safer for a pipeline that deploys one)
                    </label>
                    {limited && (
                        <ul className="max-h-40 space-y-1 overflow-y-auto rounded-field border border-base-300 p-3">
                            {apps.length === 0 && (
                                <li className="text-sm text-base-content/70">
                                    There are no apps yet.
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

                <Select
                    label="Valid for"
                    value={form.data.days}
                    onChange={(e) =>
                        form.setData('days', Number(e.target.value))
                    }
                    error={form.errors.days}
                    hint="It stops working then, and you make a new one. A token that never expires is not offered."
                >
                    {LIFETIMES.map((l) => (
                        <option key={l.days} value={l.days}>
                            {l.label}
                        </option>
                    ))}
                </Select>

                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={
                            form.data.abilities.length === 0 ||
                            (limited && form.data.apps.length === 0)
                        }
                    >
                        Create token
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
