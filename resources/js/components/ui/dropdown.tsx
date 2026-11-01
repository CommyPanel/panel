import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    /** Accessible name of the trigger button. */
    label: string;
    trigger: ReactNode;
    children: (close: () => void) => ReactNode;
    align?: 'start' | 'end';
    className?: string;
    /** Button style of the trigger; a small text button by default. */
    triggerClassName?: string;
};

/**
 * A small menu button. Closes on outside click and Escape; focus returns to
 * the trigger when it closes with the keyboard.
 */
export function Dropdown({
    label,
    trigger,
    children,
    align = 'end',
    className,
    triggerClassName = 'btn-text btn-sm',
}: Props) {
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const button = useRef<HTMLButtonElement>(null);
    const menuId = useId();

    useEffect(() => {
        if (!open) return;

        const onPointer = (event: MouseEvent) => {
            if (!root.current?.contains(event.target as Node)) setOpen(false);
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                button.current?.focus();
            }
        };

        document.addEventListener('mousedown', onPointer);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div ref={root} className={cn('relative', className)}>
            <button
                ref={button}
                type="button"
                aria-label={label}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                className={cn('btn gap-2', triggerClassName)}
                onClick={() => setOpen((value) => !value)}
            >
                {trigger}
            </button>
            {open && (
                <div
                    id={menuId}
                    role="menu"
                    className={cn(
                        'absolute z-50 mt-2 min-w-56 rounded-box border border-base-300 bg-base-100 p-1 shadow-lg',
                        align === 'end' ? 'right-0' : 'left-0',
                    )}
                >
                    {children(() => setOpen(false))}
                </div>
            )}
        </div>
    );
}

type ItemProps = {
    children: ReactNode;
    onSelect: () => void;
    selected?: boolean;
    radio?: boolean;
    tone?: 'default' | 'danger';
};

export function DropdownItem({
    children,
    onSelect,
    selected,
    radio,
    tone = 'default',
}: ItemProps) {
    return (
        <button
            type="button"
            role={radio ? 'menuitemradio' : 'menuitem'}
            aria-checked={radio ? selected : undefined}
            onClick={onSelect}
            className={cn(
                'flex w-full items-center gap-3 rounded-field px-3 py-2 text-left text-sm outline-none hover:bg-base-200 focus-visible:bg-base-200',
                selected && 'font-medium text-primary',
                tone === 'danger' && 'text-error',
            )}
        >
            {children}
        </button>
    );
}
