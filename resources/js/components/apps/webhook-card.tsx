import { router } from '@inertiajs/react';
import { Webhook } from 'lucide-react';
import { useState } from 'react';
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
