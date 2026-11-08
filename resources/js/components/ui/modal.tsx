import { useEffect, useId, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';

type Props = {
    open: boolean;
    onClose: () => void;
    title: string;
    children: ReactNode;
    footer?: ReactNode;
    size?: 'sm' | 'md' | 'lg';
};

const sizes = {
    sm: 'modal-dialog-sm',
    md: 'modal-dialog-md',
    lg: 'modal-dialog-lg',
};

/**
 * FlyonUI modal styling driven by React state. FlyonUI's JS plugins are not
 * used because they must be re-initialised on every Inertia visit; here the
 * `open` class that its `overlay-open:` variants key on is toggled by React.
 */
export function Modal({
    open,
    onClose,
    title,
    children,
    footer,
    size = 'md',
}: Props) {
    const { t } = useT();
    const titleId = useId();
    const [mounted, setMounted] = useState(open);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (open) {
            setMounted(true);
            const frame = requestAnimationFrame(() => setVisible(true));
            return () => cancelAnimationFrame(frame);
        }

        setVisible(false);
        const timer = setTimeout(() => setMounted(false), 200);
        return () => clearTimeout(timer);
    }, [open]);

    useEffect(() => {
        if (!open) return;

        const onKey = (event: KeyboardEvent) =>
            event.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [open, onClose]);

    if (!mounted) return null;

    return createPortal(
        <div
            className={cn(
                'modal modal-middle bg-base-content/40',
                visible && 'open pointer-events-auto opacity-100',
            )}
            onMouseDown={(event) =>
                event.target === event.currentTarget && onClose()
            }
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                className={cn('modal-dialog', sizes[size])}
            >
                <div className="modal-content">
                    <div className="modal-header">
                        <h3 id={titleId} className="modal-title">
                            {title}
                        </h3>
                        <button
                            type="button"
                            className="btn btn-circle btn-text btn-sm"
                            aria-label={t('Close')}
                            onClick={onClose}
                        >
                            ✕
                        </button>
                    </div>
                    <div className="modal-body">{children}</div>
                    {footer && <div className="modal-footer">{footer}</div>}
                </div>
            </div>
        </div>,
        document.body,
    );
}
