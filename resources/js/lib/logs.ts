// Colours and cursor codes that apps print into their logs. They are text noise here.
// eslint-disable-next-line no-control-regex
const ANSI = /\u001b\[[0-9;?]*[A-Za-z]/g;
// eslint-disable-next-line no-control-regex
const CONTROL = /[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/g;

export function cleanLog(text: string): string {
    return text.replace(ANSI, '').replace(CONTROL, '').replace(/\r\n?/g, '\n');
}

export type LogPart = { text: string; match: number | null };

/**
 * Splits the text around every case-insensitive occurrence of the query. The
 * query is plain text, never a pattern. Matches are numbered from 0.
 */
export function splitMatches(
    text: string,
    query: string,
): { parts: LogPart[]; count: number } {
    if (query === '') {
        return { parts: [{ text, match: null }], count: 0 };
    }

    const haystack = text.toLowerCase();
    const needle = query.toLowerCase();
    const parts: LogPart[] = [];
    let from = 0;
    let count = 0;

    for (;;) {
        const at = haystack.indexOf(needle, from);

        if (at === -1) break;

        if (at > from) parts.push({ text: text.slice(from, at), match: null });
        parts.push({
            text: text.slice(at, at + needle.length),
            match: count++,
        });
        from = at + needle.length;
    }

    if (from < text.length) parts.push({ text: text.slice(from), match: null });

    return { parts, count };
}
