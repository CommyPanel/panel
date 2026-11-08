import { Link, router, usePage } from '@inertiajs/react';
import { Check, Copy, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ApiTokenController from '@/actions/App/Http/Controllers/ApiTokenController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { useT } from '@/hooks/use-t';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import AppLayout from '@/layouts/app-layout';
import { formatDate, timeAgo } from '@/lib/format';
import { Trans } from '@/lib/trans';

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
    can_create: boolean;
    new_token: { name: string; token: string } | null;
    api_url: string;
};

export default function Tokens({
    tokens,
    can_create,
    new_token,
    api_url,
}: Props) {
    const { t } = useT();
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();
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
        <AppLayout title={t('API tokens')}>
            <PageHeader
                title={t('API tokens')}
                description={t(
                    'Let a script or a CI pipeline start deployments and read how they went, without your password.',
                )}
                actions={
                    can_create && (
                        <Link
                            href={ApiTokenController.create()}
                            className="btn btn-primary"
                        >
                            <Plus className="size-4" aria-hidden />
                            {t('New token')}
                        </Link>
                    )
                }
            />

            {new_token && <NewToken token={new_token} apiUrl={api_url} />}

            {!can_create && (
                <Alert variant="info">
                    {t(
                        'Your account may not view or deploy apps, so there is nothing a token could do for you. Ask an administrator for those permissions.',
                    )}
                </Alert>
            )}

            <Card
                title={t('Your tokens')}
                description={t(
                    'A token can do only what you may do, now and later: if you lose a permission, your tokens lose it too.',
                )}
                flush={tokens.length > 0}
            >
                {tokens.length === 0 ? (
                    <p className="text-sm text-base-content/70">
                        {t(
                            'You have no tokens. Make one for each script that needs access, so you can revoke it on its own.',
                        )}
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>{t('Name')}</th>
                                    <th>{t('May')}</th>
                                    <th>{t('Apps')}</th>
                                    <th>{t('Last used')}</th>
                                    <th>{t('Expires')}</th>
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
                                                t('All apps')
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
                                                : t('Never')}
                                        </td>
                                        <td className="text-sm whitespace-nowrap">
                                            {token.expired ? (
                                                <span className="badge-soft badge badge-error">
                                                    {t('Expired')}
                                                </span>
                                            ) : token.expires_at ? (
                                                formatDate(token.expires_at)
                                            ) : (
                                                <span className="badge-soft badge badge-error">
                                                    {t('Never expires')}
                                                </span>
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
                                                {t('Revoke')}
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
                title={t('Using a token')}
                description={t(
                    'Send it as a bearer token. Every call needs https.',
                )}
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
                    {t(
                        "Keep the token in your CI's secret store, never in a repository. If one leaks, revoke it here; it stops working at once. Failed attempts are limited, and every deployment a token starts is recorded in the audit log with its name.",
                    )}
                </p>
            </Card>

            {errors.name && <Alert variant="error">{errors.name}</Alert>}

            <ConfirmModal
                open={revoking !== null}
                title={t('Revoke this token?')}
                confirmLabel={t('Revoke token')}
                onConfirm={revoke}
                onClose={() => setRevoking(null)}
            >
                <Trans
                    text="<b>:name</b> stops working at once. A script that still uses it will get 401 errors until it has a new token."
                    components={{ b: <strong /> }}
                    values={{ name: revoking?.name ?? '' }}
                />
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
    const { t } = useT();
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
                    {t(
                        'The token :name was made. Copy it now: it is shown only this once and cannot be shown again.',
                        { name: token.name },
                    )}
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
                        {copied ? t('Copied') : t('Copy')}
                    </Button>
                </div>
                <p className="text-sm">
                    <Trans
                        text="Use it as <code>Authorization: Bearer …</code> against <code2>:url</code2>."
                        components={{ code: <code />, code2: <code /> }}
                        values={{ url: apiUrl }}
                    />
                </p>
            </div>
        </Alert>
    );
}
