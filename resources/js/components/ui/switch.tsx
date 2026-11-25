import { cn } from '@/lib/utils';

type Props = {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    /** What the switch turns on or off, read out by assistive technology. */
    label: string;
    disabled?: boolean;
    id?: string;
    className?: string;
};

/**
 * An on/off switch. It is a button with `role="switch"`, so Space and Enter toggle it and a screen reader
 * announces its state. The look is the track and thumb below, in theme colours.
 */
export function Switch({
    checked,
    onCheckedChange,
    label,
    disabled = false,
    id,
    className,
}: Props) {
    return (
        <button
            id={id}
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition-colors duration-200 motion-reduce:transition-none',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                checked
                    ? 'border-primary bg-primary'
                    : 'border-base-300 bg-base-300',
                disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
                className,
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'pointer-events-none inline-block size-4.5 rounded-full bg-base-100 shadow transition-transform duration-200 motion-reduce:transition-none',
                    checked ? 'translate-x-5.5' : 'translate-x-0.5',
                )}
            />
        </button>
    );
}
