import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';

type Props = {
    open: boolean;
    title: string;
    children: ReactNode;
    /** What has to be typed, exactly, before the button works. */
    expected: string;
    confirmLabel: string;
    loading?: boolean;
    error?: string;
    onConfirm: (typed: string) => void;
    onClose: () => void;
};

/** Like deleting an app: the destructive button works only after the name is typed. */
export function TypedConfirmModal({
    open,
    title,
    children,
    expected,
    confirmLabel,
    loading = false,
    error,
    onConfirm,
    onClose,
}: Props) {
    const [typed, setTyped] = useState('');

    const close = () => {
        setTyped('');
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (typed === expected) onConfirm(typed);
    };

    return (
        <Modal open={open} onClose={close} title={title} size="md">
            <form onSubmit={submit} className="space-y-4">
                <div className="space-y-3 text-sm">{children}</div>
                <Input
                    label={`Type ${expected} to confirm`}
                    autoComplete="off"
                    autoFocus
                    spellCheck={false}
                    value={typed}
                    onChange={(e) => setTyped(e.target.value)}
                    error={error}
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="error"
                        loading={loading}
                        disabled={typed !== expected}
                    >
                        {confirmLabel}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
