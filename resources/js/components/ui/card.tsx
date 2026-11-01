import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    title?: string;
    description?: string;
    actions?: ReactNode;
    /** Leave it out for a card that is only a heading with an action. */
    children?: ReactNode;
    className?: string;
    /** Remove the body padding, e.g. for a table that spans the card. */
    flush?: boolean;
};

export function Card({
    title,
    description,
    actions,
    children,
    className,
    flush = false,
}: Props) {
    const hasBody =
        children !== undefined && children !== null && children !== false;

    return (
        <section
            className={cn(
                'w-full min-w-0 overflow-hidden rounded-box border border-base-300 bg-base-100',
                className,
            )}
        >
            {(title || actions) && (
                <header
                    className={cn(
                        'flex flex-wrap justify-between gap-3 px-5 py-4 sm:px-6',
                        hasBody
                            ? 'items-start border-b border-base-300'
                            : 'items-center',
                    )}
                >
                    {/* The text takes the room that is left, so an action stays on the right on a wide screen and drops below the text on a narrow one. */}
                    <div className="min-w-0 flex-1 basis-64 space-y-1">
                        {title && (
                            <h2 className="text-base font-semibold">{title}</h2>
                        )}
                        {description && (
                            <p className="text-sm text-base-content/70">
                                {description}
                            </p>
                        )}
                    </div>
                    {actions}
                </header>
            )}
            {hasBody && (
                <div className={cn(!flush && 'space-y-4 p-5 sm:p-6')}>
                    {children}
                </div>
            )}
        </section>
    );
}
