import type { InputHTMLAttributes } from 'react';
import { useId } from 'react';
import { cn } from '@/lib/utils';

type Props = InputHTMLAttributes<HTMLInputElement> & {
    label: string;
    error?: string;
    hint?: string;
};

export function Input({ label, error, hint, className, id, ...props }: Props) {
    const generated = useId();
    // A browser may put the saved email address into the first plain text field of a form (an app name became
    // "me@example.com", and with it a container and image name). Plain text fields ask not to be filled; email
    // and password fields, which are for signing in and accounts, keep the browser's own behaviour unless
    // the caller says otherwise.
    const plainText = props.type === undefined || props.type === 'text';
    const hints = plainText
        ? { 'data-1p-ignore': true, 'data-lpignore': 'true' }
        : {};
    const inputId = id ?? generated;
    const describedBy = error || hint ? `${inputId}-help` : undefined;

    return (
        <div className="w-full">
            <label className="mb-1 label-text block px-0!" htmlFor={inputId}>
                {label}
            </label>
            <input
                id={inputId}
                className={cn('input w-full', error && 'is-invalid', className)}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                autoComplete={
                    props.autoComplete ?? (plainText ? 'off' : undefined)
                }
                {...hints}
                {...props}
            />
            {(error || hint) && (
                <span
                    id={describedBy}
                    className={cn(
                        'mt-1 block text-sm',
                        error ? 'text-error' : 'text-base-content/60',
                    )}
                >
                    {error ?? hint}
                </span>
            )}
        </div>
    );
}
