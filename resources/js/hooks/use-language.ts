import { router, usePage } from '@inertiajs/react';
import LanguageController from '@/actions/App/Http/Controllers/LanguageController';

/**
 * Changes the language of the panel. A signed-in person saves it for their account and the page
 * starts over in it. Before sign-in (login, first setup) it is kept for the session and the page
 * shows it at once.
 */
export function useChangeLanguage() {
    const { auth } = usePage<{ auth?: { user: unknown } }>().props;
    const signedIn = auth?.user != null;

    return (code: string) => {
        if (signedIn) {
            router.put(
                LanguageController.update().url,
                { locale: code },
                {
                    preserveScroll: true,
                    // Every sentence of the page changes: start the page over in the new language.
                    onSuccess: () => window.location.reload(),
                },
            );

            return;
        }

        router.get(
            window.location.pathname,
            { lang: code },
            {
                only: ['locale', 'translations'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };
}
