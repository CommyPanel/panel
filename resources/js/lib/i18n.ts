/**
 * Translations for the panel's interface.
 *
 * A sentence is its own key: `t('Save settings')`. English is the fallback, so a sentence that is
 * missing from the language file shows in English. The language's sentences come from the server
 * (the shared `translations` prop, sent once per language); `lang/<code>.json` holds them, and
 * the same files serve the PHP side.
 *
 * - Placeholders are written `:name`: `t('Deployed :app', { app })`.
 * - Plurals list the forms of the language, `one`, `few`, `many`, `other`, in one sentence:
 *   `tn('one::count deployment|other::count deployments', n)`. A language lists only the forms it has.
 * - Always pass a string literal as the key, so `npm run i18n:check` can find it.
 */

export type Params = Record<string, string | number>;

type Dictionary = Record<string, string>;

/** What the server sends: the sentences of one language, and which language that is. */
export type Bundle = { locale: string; strings: Dictionary };

let dictionary: Dictionary = {};
let currentLocale = 'en';
// Every language's sentences the browser has received, so a page that comes back in a language
// seen before never gets another language's sentences by mistake.
const received = new Map<string, Dictionary>();

/** Called with the shared props of every page, so the current language is always the page's. */
export function setLanguage(locale: string, bundle: Bundle | undefined) {
    if (bundle && !Array.isArray(bundle.strings) && bundle.locale) {
        received.set(bundle.locale, bundle.strings);
    } else if (bundle && bundle.locale) {
        received.set(bundle.locale, {});
    }

    currentLocale = locale;
    dictionary = received.get(locale) ?? {};

    if (typeof document !== 'undefined') document.documentElement.lang = locale;
}

export function getLocale(): string {
    return currentLocale;
}

function fill(text: string, params?: Params): string {
    if (!params) return text;

    // Longest names first, so ":count" never eats part of ":counter".
    return Object.keys(params)
        .sort((a, b) => b.length - a.length)
        .reduce(
            (out, name) => out.split(`:${name}`).join(String(params[name])),
            text,
        );
}

export function t(key: string, params?: Params): string {
    return fill(dictionary[key] ?? key, params);
}

/** "one:1 app|other::count apps" -> { one: "...", other: "..." } */
function forms(text: string): Record<string, string> {
    const out: Record<string, string> = {};

    for (const part of text.split('|')) {
        const at = part.indexOf(':');
        // "one::count apps" is the form `one` with the text ":count apps".
        if (at > 0) out[part.slice(0, at)] = part.slice(at + 1);
    }

    return out;
}

/** A sentence with a number in it: picks the form the language uses for that number. */
export function tn(key: string, count: number, params?: Params): string {
    const available = forms(dictionary[key] ?? key);
    const category = new Intl.PluralRules(currentLocale).select(count);
    const text =
        available[category] ??
        available.other ??
        Object.values(available)[0] ??
        key;

    return fill(text, { count: formatNumber(count), ...params });
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat(currentLocale).format(value);
}
