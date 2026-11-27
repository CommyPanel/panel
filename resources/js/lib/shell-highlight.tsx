import type { ReactNode } from 'react';

const comment = 'text-base-content/60 italic';

/** Quoted text, variables, operators and a trailing comment, in that order of precedence. */
const TOKEN =
    /("(?:[^"\\]|\\.)*"?|'[^']*'?|\$\{[^}]*\}?|\$[A-Za-z_][A-Za-z0-9_]*|\$[?!#@*$0-9]|&&|\|\||[|;<>&]+|(?:^|\s)#.*$)/g;

function token(part: string, key: number): ReactNode {
    if (part.startsWith('"') || part.startsWith("'")) {
        // A double-quoted string still expands variables, so they keep their own colour inside it.
        return part.startsWith('"') ? (
            <span key={key} className="text-success">
                {part
                    .split(/(\$\{[^}]*\}?|\$[A-Za-z_][A-Za-z0-9_]*)/)
                    .map((p, i) =>
                        p.startsWith('$') ? (
                            <span key={i} className="text-warning">
                                {p}
                            </span>
                        ) : (
                            p
                        ),
                    )}
            </span>
        ) : (
            <span key={key} className="text-success">
                {part}
            </span>
        );
    }

    if (part.startsWith('$')) {
        return (
            <span key={key} className="text-warning">
                {part}
            </span>
        );
    }

    if (/^\s*#/.test(part)) {
        return (
            <span key={key} className={comment}>
                {part}
            </span>
        );
    }

    return (
        <span key={key} className="text-primary">
            {part}
        </span>
    );
}

/**
 * Colours the lines of a shell script: comments muted, quoted text green, `$VARIABLES` highlighted,
 * operators (`&&`, `|`, `;`, `>`) in the theme colour. One element per line, as the code editor wants
 * them. It is not a parser: a string that runs over several lines is coloured line by line.
 */
export function highlightShell(text: string): ReactNode[] {
    return text.split('\n').map((line) => {
        if (/^\s*#/.test(line)) {
            return <span className={comment}>{line}</span>;
        }

        const parts = line.split(TOKEN);

        return (
            <>
                {parts.map((part, i) =>
                    i % 2 === 1 ? token(part, i) : <span key={i}>{part}</span>,
                )}
            </>
        );
    });
}
