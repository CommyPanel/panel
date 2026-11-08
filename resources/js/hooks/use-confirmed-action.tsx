import { useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { useT } from '@/hooks/use-t';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { fortify } from '@/lib/fortify';
import { jsonHeaders } from '@/lib/post-json';

/**
 * Runs an action that sits behind Laravel's `password.confirm` middleware.
 * That middleware answers a POST with a redirect that cannot be replayed, so
 * the password is confirmed first (only when there is no recent confirmation)
 * in a modal, and the action runs afterwards. The server still enforces it.
 */
export function useConfirmedAction(): {
    run: (action: () => void) => Promise<void>;
    modal: ReactNode;
} {
    const { t } = useT();
    const pending = useRef<(() => void) | null>(null);
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const run = async (action: () => void) => {
        try {
            const response = await fetch(fortify.confirmedPasswordStatus, {
                credentials: 'same-origin',
                headers: jsonHeaders(),
            });
            const body = (await response.json()) as { confirmed?: boolean };
            if (body.confirmed) {
                action();
                return;
            }
        } catch {
            // Ask for the password; the server decides either way.
        }

        pending.current = action;
        setPassword('');
        setError(null);
        setOpen(true);
    };

    const close = () => {
        pending.current = null;
        setOpen(false);
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(fortify.confirmPassword, {
                method: 'POST',
                credentials: 'same-origin',
                headers: jsonHeaders(),
                body: JSON.stringify({ password }),
            });
            if (!response.ok) {
                setError(
                    response.status === 422
                        ? t('That password is not correct.')
                        : t('The password could not be checked. Try again.'),
                );
                return;
            }
            const action = pending.current;
            close();
            action?.();
        } catch {
            setError(t('The password could not be checked. Try again.'));
        } finally {
            setBusy(false);
        }
    };

    const modal = (
        <Modal
            open={open}
            onClose={close}
            title={t('Confirm your password')}
            size="sm"
        >
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-base-content/70">
                    {t(
                        'This action affects the whole server. Enter your password to continue.',
                    )}
                </p>
                <Input
                    label={t('Password')}
                    type="password"
                    autoComplete="current-password"
                    required
                    autoFocus
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    error={error ?? undefined}
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={busy}>
                        {t('Confirm')}
                    </Button>
                </div>
            </form>
        </Modal>
    );

    return { run, modal };
}
