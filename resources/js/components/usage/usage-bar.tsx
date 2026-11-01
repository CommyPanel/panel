import { cn } from '@/lib/utils';

/** A thin themed bar for "how full": warns from 75% and turns red from 90%. */
export function UsageBar({
    percent,
    label,
    className,
}: {
    percent: number;
    label: string;
    className?: string;
}) {
    const value = Math.min(Math.max(percent, 0), 100);

    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={Math.round(value)}
            className={cn(
                'h-2 w-full overflow-hidden rounded-full bg-base-300',
                className,
            )}
        >
            <div
                className={cn(
                    'h-full rounded-full',
                    value >= 90
                        ? 'bg-error'
                        : value >= 75
                          ? 'bg-warning'
                          : 'bg-primary',
                )}
                style={{ width: `${value}%` }}
            />
        </div>
    );
}
