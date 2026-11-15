import { Link } from '@inertiajs/react';
import { Check, Copy, Eye, EyeOff, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import SetupLayout from '@/layouts/setup-layout';

type Props = { encryption_key: string; setup: boolean };

function KeyCard({ secret }: { secret: string }) {
    const { t } = useT();
    const [shown, setShown] = useState(false);
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(secret);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard access can be refused; reveal the key and select it by hand.
        }
    };

    return (
        <Card
            title={t('Encryption key')}
            description={t(
                'This key (APP_KEY) encrypts the secrets stored in the panel: environment variables, database passwords and tokens.',
            )}
        >
            <div className="space-y-4">
                <Alert variant="warning">
                    <TriangleAlert className="size-4 shrink-0" aria-hidden />
                    <span>
                        {t(
                            'Save this key somewhere safe, such as a password manager. Backups never contain it. If it is lost, restoring the panel on a new server cannot read those secrets and recovery may fail.',
                        )}
                    </span>
                </Alert>
                <div className="flex items-center gap-2">
                    <input
                        type={shown ? 'text' : 'password'}
                        readOnly
                        value={secret}
                        aria-label={t('Encryption key')}
                        autoComplete="off"
                        spellCheck={false}
                        className="input w-full font-mono text-sm"
                        onFocus={(event) => event.currentTarget.select()}
                    />
                    <Button
                        variant="soft"
                        onClick={() => setShown(!shown)}
                        aria-label={shown ? t('Hide') : t('Show')}
                    >
                        {shown ? (
                            <EyeOff className="size-4" aria-hidden />
                        ) : (
                            <Eye className="size-4" aria-hidden />
                        )}
                    </Button>
                    <Button
                        variant="soft"
                        onClick={copy}
                        aria-label={t('Copy')}
                    >
                        {copied ? (
                            <Check className="size-4" aria-hidden />
                        ) : (
                            <Copy className="size-4" aria-hidden />
                        )}
                    </Button>
                </div>
                <p className="text-sm text-base-content/70">
                    {t(
                        'Opening this page is recorded in the audit log as a high-risk action. The key is also in /etc/commypanel/env on the server.',
                    )}
                </p>
            </div>
        </Card>
    );
}

export default function EncryptionKey({
    encryption_key: secret,
    setup,
}: Props) {
    const { t } = useT();
    const [saved, setSaved] = useState(false);

    if (setup) {
        return (
            <SetupLayout
                title={t('Save your encryption key')}
                description={t(
                    "The last step: keep a copy of the key that protects the panel's stored secrets.",
                )}
                step={3}
            >
                <KeyCard secret={secret} />
                <label className="flex items-start gap-3 text-sm">
                    <input
                        type="checkbox"
                        className="checkbox mt-0.5 checkbox-primary"
                        checked={saved}
                        onChange={(event) => setSaved(event.target.checked)}
                    />
                    <span>{t('I have saved the key in a safe place.')}</span>
                </label>
                <div className="flex justify-end">
                    {saved ? (
                        <Link
                            href={DashboardController.url()}
                            className="btn btn-primary"
                        >
                            {t('Finish')}
                        </Link>
                    ) : (
                        <button className="btn btn-primary" disabled>
                            {t('Finish')}
                        </button>
                    )}
                </div>
            </SetupLayout>
        );
    }

    return (
        <AppLayout title={t('Encryption key')}>
            <PageHeader
                title={t('Encryption key')}
                description={t(
                    'The key that protects the secrets stored in the panel. Keep a copy somewhere safe.',
                )}
            />
            <KeyCard secret={secret} />
        </AppLayout>
    );
}
