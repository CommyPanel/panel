import { router } from '@inertiajs/react';
import { Webhook } from 'lucide-react';
import { useState } from 'react';
import AppAutoDeployController from '@/actions/App/Http/Controllers/AppAutoDeployController';
import AppWebhookController from '@/actions/App/Http/Controllers/AppWebhookController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import type { AppWebhook } from '@/types';

function CopyField({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // The value stays selectable if the clipboard is blocked.
        }
    };

    return (
        <div className="w-full">
            <span className="mb-1 label-text block px-0!">{label}</span>
            <div className="flex gap-2">
                <input
                    readOnly
                    aria-label={label}
                    className="input min-w-0 flex-1 font-mono text-sm"
                    value={value}
                    onFocus={(e) => e.currentTarget.select()}
                />
                <Button variant="soft" onClick={copy}>
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>
        </div>
    );
}

/** Deploy on push for an app read through the GitHub App: one switch, confirmed either way. */
function GitHubAutoDeploy({
    slug,
    webhook,
}: {
    slug: string;
    webhook: AppWebhook;
}) {
    const [busy, setBusy] = useState(false);
    const [asking, setAsking] = useState(false);
    const turningOn = !webhook.enabled;

    const apply = () =>
        router.put(
            AppAutoDeployController.update(slug),
            { enabled: turningOn },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setAsking(false);
                },
            },
        );

    return (
        <Card
            title="Deploy on push"
            description="Deploy automatically when the branch is pushed on GitHub. GitHub signs every call; anything else is refused."
        >
            <label className="flex cursor-pointer items-center gap-3">
                <input
                    type="checkbox"
                    role="switch"
                    className="toggle toggle-primary"
                    checked={webhook.enabled}
                    onChange={() => setAsking(true)}
                />
                <span className="text-sm">
                    {webhook.enabled
                        ? `On: a push to ${webhook.branch} deploys this app.`
                        : 'Off: only the Deploy button deploys this app.'}
                </span>
            </label>
            <ConfirmModal
                open={asking}
                title={
                    turningOn
                        ? 'Deploy on every push?'
                        : 'Stop deploying on push?'
                }
                confirmLabel={turningOn ? 'Turn on' : 'Turn off'}
                loading={busy}
                onConfirm={apply}
                onClose={() => setAsking(false)}
            >
                {turningOn
                    ? `From now on, every push to ${webhook.branch} on GitHub builds and deploys this app by itself, with no further question. A failed build keeps the running version serving. A stopped app is not started. You can turn this off again here.`
                    : `Pushes to ${webhook.branch} no longer deploy this app. Nothing running changes; use the Deploy button when you want a new version.`}
            </ConfirmModal>
        </Card>
    );
}

/** Deploy on push: the webhook URL and secret to add to the Git host. */
export function WebhookCard({
    slug,
    webhook,
}: {
    slug: string;
    webhook: AppWebhook;
}) {
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState<'renew' | 'off' | null>(null);

    if (webhook.github) {
        return <GitHubAutoDeploy slug={slug} webhook={webhook} />;
    }

    const send = (action: 'renew' | 'off' | 'on') => {
        const options = {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setConfirm(null);
            },
        };

        if (action === 'off') {
            router.delete(AppWebhookController.destroy(slug), options);
        } else {
            router.post(AppWebhookController.store(slug), {}, options);
        }
    };

    return (
        <Card
            title="Deploy on push"
            description="Deploy automatically when the branch is pushed. The Git host signs every call with the secret; anything else is refused."
        >
            {!webhook.enabled ? (
                <div>
                    <Button
                        variant="soft"
                        onClick={() => send('on')}
                        loading={busy}
                    >
                        <Webhook className="size-4" aria-hidden />
                        Turn on deploy on push
                    </Button>
                </div>
            ) : (
                <div className="space-y-4">
                    {!webhook.on_panel_domain && (
                        <Alert variant="warning">
                            The panel has no HTTPS domain yet, so this address
                            uses how you opened the panel. {webhook.provider}{' '}
                            must be able to reach it.
                        </Alert>
                    )}
                    {webhook.url && (
                        <CopyField label="URL" value={webhook.url} />
                    )}
                    {webhook.secret && (
                        <CopyField label="Secret" value={webhook.secret} />
                    )}
                    {webhook.steps.length > 0 && (
                        <div className="space-y-2 text-sm">
                            <ol className="list-decimal space-y-1 pl-5">
                                {webhook.steps.map((step) => (
                                    <li key={step}>{step}</li>
                                ))}
                            </ol>
                            {webhook.settings_url && (
                                <a
                                    className="link link-primary"
                                    href={webhook.settings_url}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                >
                                    Open {webhook.provider} webhook settings
                                </a>
                            )}
                        </div>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="soft"
                            size="sm"
                            onClick={() => setConfirm('renew')}
                        >
                            New secret
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setConfirm('off')}
                        >
                            Turn off
                        </Button>
                    </div>
                </div>
            )}
            <ConfirmModal
                open={confirm !== null}
                title={
                    confirm === 'renew'
                        ? 'Create a new secret?'
                        : 'Turn off deploy on push?'
                }
                confirmLabel={confirm === 'renew' ? 'New secret' : 'Turn off'}
                loading={busy}
                onConfirm={() => confirm && send(confirm)}
                onClose={() => setConfirm(null)}
            >
                {confirm === 'renew'
                    ? `The current secret stops working at once. Pushes are refused until you paste the new one into ${webhook.provider}.`
                    : 'Pushes no longer deploy the app. You can turn it on again later; it then gets a new address and secret.'}
            </ConfirmModal>
        </Card>
    );
}
