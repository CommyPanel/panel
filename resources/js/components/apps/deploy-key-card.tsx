import { usePage } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import DeployKeyController from '@/actions/App/Http/Controllers/DeployKeyController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';

type Props = { slug: string; publicKey: string | null };

export function DeployKeyCard({ slug, publicKey }: Props) {
    const { t } = useT();
    const { errors } = usePage().props;
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(false);

    const generate = () =>
        router.post(
            DeployKeyController.store(slug),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(publicKey ?? '');
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // Clipboard access can be denied; the key stays selectable.
        }
    };

    return (
        <Card
            title={t('Deploy key')}
            description={t(
                'Needed only for private repositories. Add the public key to your repository as a read-only deploy key.',
            )}
        >
            {errors.deploy_key && (
                <Alert variant="error">{errors.deploy_key}</Alert>
            )}
            {publicKey ? (
                <div className="space-y-3">
                    <textarea
                        readOnly
                        rows={3}
                        aria-label={t('Public deploy key')}
                        className="textarea w-full font-mono text-xs"
                        value={publicKey}
                        onFocus={(e) => e.currentTarget.select()}
                    />
                    <div>
                        <Button variant="soft" size="sm" onClick={copy}>
                            {copied ? t('Copied') : t('Copy public key')}
                        </Button>
                    </div>
                </div>
            ) : (
                <div>
                    <Button variant="soft" onClick={generate} loading={busy}>
                        {t('Generate deploy key')}
                    </Button>
                </div>
            )}
        </Card>
    );
}
