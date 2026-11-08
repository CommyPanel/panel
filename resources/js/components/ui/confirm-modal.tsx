import type { ReactNode } from 'react';
import { useT } from '@/hooks/use-t';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';

type Props = {
    open: boolean;
    title: string;
    children: ReactNode;
    confirmLabel: string;
    loading?: boolean;
    onConfirm: () => void;
    onClose: () => void;
};

/** A yes/no dialog for destructive actions that need no typed confirmation. */
export function ConfirmModal({
    open,
    title,
    children,
    confirmLabel,
    loading = false,
    onConfirm,
    onClose,
}: Props) {
    const { t } = useT();

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={title}
            size="sm"
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        variant="error"
                        loading={loading}
                        onClick={onConfirm}
                    >
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            {children}
        </Modal>
    );
}
