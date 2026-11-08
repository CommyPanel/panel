import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { useT } from '@/hooks/use-t';

/** A command to run on the server, with a button that copies it. */
export function CommandBox({ command }: { command: string }) {
    const { t } = useT();
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(command);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // The command stays selectable if the clipboard is blocked.
        }
    };

    return (
        <div className="flex items-center gap-2 rounded-field border border-base-300 bg-base-200 py-1.5 pr-1.5 pl-3">
            <code className="min-w-0 flex-1 overflow-x-auto font-mono text-sm whitespace-nowrap">
                {command}
            </code>
            <button
                type="button"
                className="btn btn-square btn-text btn-sm"
                onClick={copy}
                aria-label={copied ? t('Copied') : t('Copy the command')}
                title={copied ? t('Copied') : t('Copy')}
            >
                {copied ? (
                    <Check className="size-4 text-success" aria-hidden />
                ) : (
                    <Copy className="size-4" aria-hidden />
                )}
            </button>
        </div>
    );
}
