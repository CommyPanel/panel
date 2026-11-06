import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    variant?: 'info' | 'success' | 'warning' | 'error';
    children: ReactNode;
    className?: string;
    /** A dashed outline, for a warning that should not be skimmed past. */
    dashed?: boolean;
};

const variants = {
    info: 'alert-info',
    success: 'alert-success',
    warning: 'alert-warning',
    error: 'alert-error',
};

export function Alert({
    variant = 'info',
    children,
    className,
    dashed = false,
}: Props) {
    return (
        <div
            role={variant === 'error' ? 'alert' : 'status'}
            className={cn(
                'alert-soft alert',
                variants[variant],
                dashed && 'border-2 border-dashed border-current',
                className,
            )}
        >
            {children}
        </div>
    );
}
