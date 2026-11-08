import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { formatNumber, getLocale, setLanguage, t, tn } from '@/lib/i18n';
import type { Bundle, Params } from '@/lib/i18n';

/**
 * The translation helpers, for a component:
 *
 *     const { t, tn } = useT();
 *
 * Always use these inside components, not the bare `t` from `@/lib/i18n`: the functions returned
 * here change when the language changes, which is what makes the React compiler draw the text again.
 * (The bare `t` is for text made outside components, and takes its language from the last page.)
 */
export function useT() {
    const { locale, translations } = usePage<{
        locale?: string;
        translations?: Bundle;
    }>().props;
    const language = locale ?? 'en';

    // A new page can arrive in another language (a person changed it). The sentences have to be in
    // place before this component draws, so they are set here and not after the visit has finished.
    if (language !== getLocale()) setLanguage(language, translations);

    return useMemo(
        () => ({
            locale: language,
            t: (key: string, params?: Params) => t(key, params),
            tn: (key: string, count: number, params?: Params) =>
                tn(key, count, params),
            formatNumber: (value: number) => formatNumber(value),
        }),
        // The language is what makes these different.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [language],
    );
}
