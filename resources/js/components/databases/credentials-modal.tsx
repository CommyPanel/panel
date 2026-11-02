import { Check, Copy, Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import type { DatabaseCredentials } from '@/types';

function Field({
    label,
    value,
    secret = false,
    revealed = false,
}: {
    label: string;
    value: string;
    secret?: boolean;
    revealed?: boolean;
}) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard access can be refused; the text can still be selected by hand.
        }
    };

    return (
        <div className="space-y-1">
            <dt className="text-sm text-base-content/70">{label}</dt>
            <dd className="flex items-center gap-2">
                <code className="min-w-0 flex-1 rounded-field bg-base-200 px-3 py-2 text-sm break-all">
                    {secret && !revealed ? '••••••••••••••••' : value}
                </code>
                <Button
                    variant="soft"
                    size="sm"
                    onClick={() => void copy()}
                    aria-label={`Copy ${label}`}
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
 * The connection of one database, with its password. It was fetched after a
 * password confirmation and is dropped from memory when the dialog closes.
 */
export function CredentialsModal({
    credentials,
    onClose,
}: {
    credentials: DatabaseCredentials | null;
    onClose: () => void;
}) {
    const [revealed, setRevealed] = useState(false);

    const close = () => {
        setRevealed(false);
        onClose();
    };

    return (
        <Modal
            open={credentials !== null}
            onClose={close}
            title="Connection"
            size="lg"
        >
            {credentials && (
                <div className="space-y-4">
                    <dl className="space-y-3">
                        <Field
                            label="Address (what your app connects to)"
                            value={`${credentials.host}:${credentials.port}`}
                        />
                        <Field label="Database" value={credentials.name} />
                        <Field label="User" value={credentials.user} />
                        <Field
                            label="Password"
                            value={credentials.password}
                            secret
                            revealed={revealed}
                        />
                        <Field
                            label="Connection URL"
                            value={credentials.url}
                            secret
                            revealed={revealed}
                        />
                    </dl>
                    <p className="text-sm text-base-content/70">
                        The address works only from an app that is linked to
                        this database. It is not reachable from the internet.
                        Showing this is recorded in the audit log.
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
                                ? 'Hide the password'
                                : 'Show the password'}
                        </Button>
                        <Button onClick={close}>Close</Button>
                    </div>
                </div>
            )}
        </Modal>
    );
}
