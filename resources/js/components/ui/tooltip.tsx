import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    /** The words shown on hover and keyboard focus. */
    label: string;
    children: ReactNode;
    /** Which edge of the trigger the bubble lines up with. */
    align?: 'start' | 'end';
    /** Below the trigger by default; use `top` where a card or the page edge would cut it off. */
    side?: 'bottom' | 'top';
    className?: string;
};

/**
 * A small CSS-only tooltip. It shows on hover and when something inside has
 * keyboard focus. The label is not announced twice: give the focusable child
 * its own aria-label, and the bubble is hidden from assistive technology.
 */
export function Tooltip({
    label,
    children,
    align = 'end',
    side = 'bottom',
    className,
}: Props) {
    return (
        <span className={cn('group relative inline-flex', className)}>
            {children}
            <span
                aria-hidden
                className={cn(
                    'pointer-events-none absolute z-50 w-max max-w-64 rounded-field bg-base-content px-3 py-1.5 text-xs font-medium text-base-100 opacity-0 shadow-lg transition-opacity duration-150',
                    'group-focus-within:opacity-100 group-hover:opacity-100',
                    side === 'bottom' ? 'top-full mt-2' : 'bottom-full mb-2',
                    align === 'end' ? 'right-0' : 'left-0',
                )}
            >
                {label}
            </span>
        </span>
    );
}
