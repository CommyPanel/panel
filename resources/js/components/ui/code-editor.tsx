import { useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';

const LINE_HEIGHT = 24;
const PADDING = 8;

type Props = {
    value: string;
    onChange: (value: string) => void;
    label: string;
    /** Called for Cmd/Ctrl+S. */
    onSave?: () => void;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
    /** The editor is at least this many lines tall, and at most `maxLines`. */
    minLines?: number;
    maxLines?: number;
};

/**
 * A plain text editor with a line-number gutter, for config files. Tab
 * indents; Escape followed by Tab moves focus on, so the keyboard never gets
 * stuck in it. Long lines scroll sideways instead of wrapping, so the numbers
 * always line up with the text.
 */
export function CodeEditor({
    value,
    onChange,
    label,
    onSave,
    disabled,
    invalid,
    describedBy,
    minLines = 12,
    maxLines = 28,
}: Props) {
    const area = useRef<HTMLTextAreaElement>(null);
    const leaveOnTab = useRef(false);
    const [scroll, setScroll] = useState(0);

    const lines = value.split('\n').length;
    const height =
        Math.min(Math.max(lines, minLines), maxLines) * LINE_HEIGHT +
        PADDING * 2;

    const keyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (
            (event.metaKey || event.ctrlKey) &&
            event.key.toLowerCase() === 's'
        ) {
            event.preventDefault();
            onSave?.();

            return;
        }

        if (event.key === 'Escape') {
            leaveOnTab.current = true;

            return;
        }

        if (event.key === 'Tab' && !event.shiftKey && !leaveOnTab.current) {
            event.preventDefault();
            const el = event.currentTarget;
            el.setRangeText('    ', el.selectionStart, el.selectionEnd, 'end');
            onChange(el.value);

            return;
        }

        if (event.key !== 'Tab' && event.key !== 'Shift') {
            leaveOnTab.current = false;
        }
    };

    return (
        <div
            className={cn(
                'flex overflow-hidden rounded-field border bg-base-100',
                invalid
                    ? 'border-error'
                    : 'border-base-300 focus-within:border-primary',
                disabled && 'opacity-60',
            )}
        >
            <div
                aria-hidden
                className="w-12 shrink-0 overflow-hidden border-r border-base-300 bg-base-200 text-right font-mono text-xs text-base-content/60 select-none"
                style={{ height }}
            >
                <div
                    style={{
                        transform: `translateY(${PADDING - scroll}px)`,
                    }}
                >
                    {Array.from({ length: lines }, (_, i) => (
                        <div
                            key={i}
                            className="pr-2"
                            style={{
                                height: LINE_HEIGHT,
                                lineHeight: `${LINE_HEIGHT}px`,
                            }}
                        >
                            {i + 1}
                        </div>
                    ))}
                </div>
            </div>
            <textarea
                ref={area}
                aria-label={label}
                aria-invalid={invalid || undefined}
                aria-describedby={describedBy}
                value={value}
                disabled={disabled}
                wrap="off"
                spellCheck={false}
                autoCapitalize="off"
                autoCorrect="off"
                autoComplete="off"
                className="min-w-0 flex-1 resize-none overflow-auto bg-transparent px-3 font-mono text-sm whitespace-pre outline-none"
                style={{
                    height,
                    lineHeight: `${LINE_HEIGHT}px`,
                    paddingTop: PADDING,
                    paddingBottom: PADDING,
                }}
                onChange={(event) => onChange(event.target.value)}
                onKeyDown={keyDown}
                onBlur={() => {
                    leaveOnTab.current = false;
                }}
                onScroll={(event) => setScroll(event.currentTarget.scrollTop)}
            />
        </div>
    );
}
