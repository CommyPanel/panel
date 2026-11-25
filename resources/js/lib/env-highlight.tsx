import type { ReactNode } from 'react';

const comment = 'text-base-content/60 italic';

/** `${VAR}` inside a value gets its own colour; the rest is the value's. */
function withReferences(text: string, className?: string): ReactNode[] {
    return text.split(/(\$\{[^}]*\})/).map((part, i) =>
        part.startsWith('${') ? (
            <span key={i} className="text-warning">
                {part}
            </span>
        ) : (
            <span key={i} className={className}>
                {part}
            </span>
        ),
    );
}

function value(rest: string): ReactNode {
    const quoted = rest.match(/^(\s*)("(?:[^"\\]|\\.)*"|'[^']*')(.*)$/);

    if (quoted) {
        return (
            <>
                {quoted[1]}
                {withReferences(quoted[2], 'text-success')}
                {quoted[3] !== '' && (
                    <span className={comment}>{quoted[3]}</span>
                )}
            </>
        );
    }

    const bare = rest.match(/^(.*?)(\s+#.*)?$/);

    return (
        <>
            {withReferences(bare?.[1] ?? rest)}
            {bare?.[2] && <span className={comment}>{bare[2]}</span>}
        </>
    );
}

/**
 * Colours the lines of a .env text: comments muted, names in the theme colour, quoted values
 * greener, `${...}` references highlighted. One element per line, as the code editor wants them.
 * A line inside a quoted value that runs over several lines is just plain text.
 */
export function highlightEnv(text: string): ReactNode[] {
    return text.split('\n').map((line) => {
        if (/^\s*#/.test(line)) {
            return <span className={comment}>{line}</span>;
        }

        const assignment = line.match(
            /^(\s*(?:export\s+)?)([A-Za-z_][A-Za-z0-9_]*)(\s*=)(.*)$/,
        );

        if (!assignment) {
            return <span>{line}</span>;
        }

        return (
            <>
                <span className="text-base-content/60">{assignment[1]}</span>
                <span className="font-medium text-primary">
                    {assignment[2]}
                </span>
                <span className="text-base-content/60">{assignment[3]}</span>
                {value(assignment[4])}
            </>
        );
    });
}
