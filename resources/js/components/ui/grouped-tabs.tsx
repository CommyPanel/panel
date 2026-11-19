import { ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type GroupedTab<T extends string> = {
    id: T;
    label: string;
    icon?: ReactNode;
    /** A small count or marker next to the label. */
    badge?: ReactNode;
};

export type TabGroup<T extends string> = {
    id: string;
    label: string;
    icon?: ReactNode;
    items: GroupedTab<T>[];
};

export type TabEntry<T extends string> = GroupedTab<T> | TabGroup<T>;

type Props<T extends string> = {
    entries: TabEntry<T>[];
    active: T;
    onChange: (id: T) => void;
    label: string;
};

const base =
    '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors sm:px-4';

function isGroup<T extends string>(entry: TabEntry<T>): entry is TabGroup<T> {
    return 'items' in entry;
}

function tone(on: boolean) {
    return on
        ? 'border-primary text-primary'
        : 'border-transparent text-base-content/70 hover:text-base-content';
}

/**
 * A navigation bar where related sections sit under a drop-down, so a long
 * list of tabs stays short. Single entries are plain buttons; a group opens a
 * menu and is marked as current while any of its sections is shown.
 */
export function GroupedTabs<T extends string>({
    entries,
    active,
    onChange,
    label,
}: Props<T>) {
    const [openId, setOpenId] = useState<string | null>(null);
    const root = useRef<HTMLElement>(null);

    useEffect(() => {
        if (openId === null) return;

        const onPointer = (event: MouseEvent) => {
            if (!root.current?.contains(event.target as Node)) {
                setOpenId(null);
            }
        };
        const onKey = (event: globalThis.KeyboardEvent) => {
            if (event.key === 'Escape') setOpenId(null);
        };

        document.addEventListener('mousedown', onPointer);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('keydown', onKey);
        };
    }, [openId]);

    // Arrow keys move between the entries of an open menu.
    const onMenuKey = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

        const items = Array.from(
            event.currentTarget.querySelectorAll<HTMLButtonElement>('button'),
        );
        const at = items.indexOf(document.activeElement as HTMLButtonElement);
        const step = event.key === 'ArrowDown' ? 1 : -1;

        event.preventDefault();
        items[(at + step + items.length) % items.length]?.focus();
    };

    return (
        <nav
            ref={root}
            aria-label={label}
            className="flex gap-1 border-b border-base-300"
        >
            {entries.map((entry) => {
                if (!isGroup(entry)) {
                    return (
                        <button
                            key={entry.id}
                            type="button"
                            aria-current={
                                entry.id === active ? 'page' : undefined
                            }
                            onClick={() => {
                                setOpenId(null);
                                onChange(entry.id);
                            }}
                            className={cn(base, tone(entry.id === active))}
                        >
                            {entry.icon}
                            {entry.label}
                            {entry.badge}
                        </button>
                    );
                }

                const open = openId === entry.id;
                const current = entry.items.some((item) => item.id === active);

                return (
                    <div key={entry.id} className="relative">
                        <button
                            type="button"
                            aria-haspopup="menu"
                            aria-expanded={open}
                            onClick={() => setOpenId(open ? null : entry.id)}
                            className={cn(base, tone(current || open))}
                        >
                            {entry.icon}
                            {entry.label}
                            <ChevronDown
                                className={cn(
                                    'size-4 transition-transform',
                                    open && 'rotate-180',
                                )}
                                aria-hidden
                            />
                        </button>
                        {open && (
                            <div
                                role="menu"
                                onKeyDown={onMenuKey}
                                className="absolute top-full left-0 z-50 mt-1 min-w-52 rounded-box border border-base-300 bg-base-100 p-1 shadow-lg"
                            >
                                {entry.items.map((item) => (
                                    <button
                                        key={item.id}
                                        type="button"
                                        role="menuitem"
                                        aria-current={
                                            item.id === active
                                                ? 'page'
                                                : undefined
                                        }
                                        onClick={() => {
                                            setOpenId(null);
                                            onChange(item.id);
                                        }}
                                        className={cn(
                                            'flex w-full items-center gap-3 rounded-field px-3 py-2 text-left text-sm outline-none hover:bg-base-200 focus-visible:bg-base-200',
                                            item.id === active &&
                                                'font-medium text-primary',
                                        )}
                                    >
                                        {item.icon}
                                        {item.label}
                                        {item.badge}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                );
            })}
        </nav>
    );
}
