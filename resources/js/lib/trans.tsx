import { cloneElement, isValidElement } from 'react';
import type { ReactElement, ReactNode } from 'react';
import { t } from '@/lib/i18n';
import type { Params } from '@/lib/i18n';

type Props = {
    /** The sentence, a string literal, with tags such as `<b>…</b>` or `<br/>` where markup goes. */
    text: string;
    /** What each tag becomes, for example `{ b: <strong /> }`. A tag with no entry shows its text. */
    components?: Record<string, ReactElement>;
    values?: Params;
};

const TAG = /<(\w+)>([\s\S]*?)<\/\1>|<(\w+)\s*\/>/;

function render(
    text: string,
    components: Record<string, ReactElement>,
    key = '',
): ReactNode[] {
    const out: ReactNode[] = [];
    let rest = text;
    let index = 0;

    while (rest.length > 0) {
        const match = TAG.exec(rest);

        if (match === null) {
            out.push(rest);
            break;
        }

        if (match.index > 0) out.push(rest.slice(0, match.index));

        const name = match[1] ?? match[3];
        const element = components[name];
        const inner =
            match[1] !== undefined
                ? render(match[2], components, `${key}${index}.`)
                : [];

        if (isValidElement(element)) {
            out.push(
                cloneElement(
                    element,
                    { key: `${key}${index}` },
                    ...(inner.length > 0 ? inner : []),
                ),
            );
        } else {
            out.push(...inner);
        }

        rest = rest.slice(match.index + match[0].length);
        index += 1;
    }

    return out;
}

/**
 * A translated sentence that has markup in it. The translator keeps the tags, so the markup lands
 * on the right words in every language:
 *
 *     <Trans text="Open <b>Domains</b> and <link>add one</link>." components={{ b: <strong />, link: <Link href="/domains" /> }} />
 */
export function Trans({ text, components = {}, values }: Props) {
    return <>{render(t(text, values), components)}</>;
}
