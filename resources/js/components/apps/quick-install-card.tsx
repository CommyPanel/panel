import { Check, Copy, Eye, EyeOff, KeyRound } from 'lucide-react';
import { useState } from 'react';
import AppCredentialsController from '@/actions/App/Http/Controllers/AppCredentialsController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Modal } from '@/components/ui/modal';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import { jsonHeaders } from '@/lib/post-json';

type Row = { label: string; value: string; secret: boolean };

function Field({ row, revealed }: { row: Row; revealed: boolean }) {
    const { t } = useT();
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(row.value);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard access can be refused; the text can still be selected by hand.
        }
    };

    return (
        <div className="space-y-1">
            <dt className="text-sm text-base-content/70">{row.label}</dt>
            <dd className="flex items-center gap-2">
                <code className="min-w-0 flex-1 rounded-field bg-base-200 px-3 py-2 text-sm break-all">
                    {row.secret && !revealed
                        ? '••••••••••••••••'
                        : row.value || '-'}
                </code>
                <Button
                    variant="soft"
                    size="sm"
                    onClick={() => void copy()}
                    aria-label={t('Copy :label', { label: row.label })}
                >
                    {copied ? (
                        <Check className="size-4" aria-hidden />
                    ) : (
                        <Copy className="size-4" aria-hidden />
                    )}
                </Button>
            </dd>
        </div>
    );
}

/**
 * What a quick-install app was made from, and a button for its generated password or key. The values
 * are fetched after a password confirmation and dropped from memory when the dialog closes.
 */
export function QuickInstallCard({
    slug,
    name,
    image,
}: {
    slug: string;
    name: string;
    image: string;
}) {
    const { t } = useT();
    const confirmed = useConfirmedAction();
    const [rows, setRows] = useState<Row[] | null>(null);
    const [revealed, setRevealed] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const load = () =>
        void confirmed.run(async () => {
            setError(null);

            try {
                const response = await fetch(
                    AppCredentialsController(slug).url,
                    { credentials: 'same-origin', headers: jsonHeaders() },
                );

                if (!response.ok) {
                    throw new Error(
                        response.status === 423
                            ? t('Confirm your password and try again.')
                            : t('The access details could not be loaded.'),
                    );
                }

                setRows(((await response.json()) as { rows: Row[] }).rows);
            } catch (e) {
                setError(
                    e instanceof Error
                        ? e.message
                        : t('The access details could not be loaded.'),
                );
            }
        });

    const close = () => {
        setRows(null);
        setRevealed(false);
    };

    return (
        <>
            <Card
                title={t('Quick install: :name', { name })}
                description={t(
                    'Runs the official image :image. The password or key was generated for you.',
                    { image },
                )}
                actions={
                    <Button variant="soft" onClick={load}>
                        <KeyRound className="size-4" aria-hidden />
                        {t('Show access details')}
                    </Button>
                }
            >
                {error && <Alert variant="error">{error}</Alert>}
            </Card>
            {confirmed.modal}
            <Modal
                open={rows !== null}
                onClose={close}
                title={t('Access details')}
                size="lg"
            >
                {rows && (
                    <div className="space-y-4">
                        <dl className="space-y-3">
                            {rows.map((row) => (
                                <Field
                                    key={row.label}
                                    row={row}
                                    revealed={revealed}
                                />
                            ))}
                        </dl>
                        <p className="text-sm text-base-content/70">
                            {t(
                                'These are the values the app is set to run with. If you change a password inside the app, this page still shows the original. Showing this is recorded in the audit log.',
                            )}
                        </p>
                        <div className="flex flex-wrap justify-between gap-2">
                            <Button
                                variant="soft"
                                onClick={() => setRevealed((r) => !r)}
                            >
                                {revealed ? (
                                    <EyeOff className="size-4" aria-hidden />
                                ) : (
                                    <Eye className="size-4" aria-hidden />
                                )}
                                {revealed
                                    ? t('Hide the secrets')
                                    : t('Show the secrets')}
                            </Button>
                            <Button onClick={close}>{t('Close')}</Button>
                        </div>
                    </div>
                )}
            </Modal>
        </>
    );
}
