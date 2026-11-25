import { router, useForm } from '@inertiajs/react';
import { Cloud } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import CloudflareController from '@/actions/App/Http/Controllers/CloudflareController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import { formatDate } from '@/lib/format';
import { Trans } from '@/lib/trans';
import type { CloudflareSettings } from '@/types';

type Props = { cloudflare: CloudflareSettings };

/**
 * The Cloudflare API token for wildcard certificates. It is tested against a domain before it is
 * saved, kept encrypted, and never shown again; saving and removing ask for the password.
 */
export function CloudflareCard({ cloudflare }: Props) {
    const { t } = useT();
    const confirmed = useConfirmedAction();
    const form = useForm({ token: '', domain: '' });
    const [removing, setRemoving] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void confirmed.run(() =>
            form.submit(CloudflareController.update(), {
                preserveScroll: true,
                onSuccess: () => form.reset(),
            }),
        );
    };

    const remove = () =>
        void confirmed.run(() =>
            router.delete(CloudflareController.destroy(), {
                preserveScroll: true,
                onStart: () => setRemoving(true),
                onFinish: () => setRemoving(false),
            }),
        );

    return (
        <Card
            title={t('Wildcard certificates')}
            description={t(
                'A wildcard domain such as *.example.com needs a certificate that is checked through DNS. Connect Cloudflare and CommyPanel makes and renews it for you.',
            )}
        >
            {confirmed.modal}

            {cloudflare.connected ? (
                <div className="space-y-4">
                    <Alert variant="success">
                        <span className="flex items-center gap-2">
                            <Cloud className="size-4 shrink-0" aria-hidden />
                            {cloudflare.zone
                                ? t(
                                      'Cloudflare is connected (checked against :zone, saved :date).',
                                      {
                                          zone: cloudflare.zone,
                                          date: formatDate(cloudflare.saved_at),
                                      },
                                  )
                                : t('Cloudflare is connected.')}
                        </span>
                    </Alert>
                    <p className="text-sm text-base-content/70">
                        {t(
                            'Wildcard certificates for domains in zones this token can edit are made and renewed automatically. Remove the token here if you stop using it, and delete it in Cloudflare too.',
                        )}
                    </p>
                    <Button variant="soft" onClick={remove} loading={removing}>
                        {t('Disconnect Cloudflare')}
                    </Button>
                </div>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    <p className="text-sm text-base-content/70">
                        <Trans
                            text="In Cloudflare open <link>My Profile > API Tokens</link> and make a token from the template Edit zone DNS, limited to the zones you use. Paste it here with one of your domains, so it can be tested."
                            components={{
                                link: (
                                    <a
                                        href="https://dash.cloudflare.com/profile/api-tokens"
                                        target="_blank"
                                        rel="noreferrer"
                                        className="link link-primary"
                                    />
                                ),
                            }}
                        />
                    </p>
                    <Input
                        label={t('API token')}
                        type="password"
                        autoComplete="off"
                        value={form.data.token}
                        onChange={(e) => form.setData('token', e.target.value)}
                        error={form.errors.token}
                        hint={t(
                            'Kept encrypted and never shown again. It can only be used to add and remove the temporary record that proves a wildcard name is yours.',
                        )}
                    />
                    <Input
                        label={t('A domain in this Cloudflare account')}
                        placeholder="example.com"
                        value={form.data.domain}
                        onChange={(e) => form.setData('domain', e.target.value)}
                        error={form.errors.domain}
                    />
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={!form.data.token || !form.data.domain}
                    >
                        {t('Test and connect')}
                    </Button>
                    <p className="text-sm text-base-content/70">
                        {t(
                            'Without Cloudflare you can still use a wildcard: CommyPanel shows a TXT record to create at your DNS provider, and asks for a new one at every renewal.',
                        )}
                    </p>
                </form>
            )}
        </Card>
    );
}
