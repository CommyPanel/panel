import { createInertiaApp, router } from '@inertiajs/react';
import { setLanguage } from '@/lib/i18n';
import type { Bundle } from '@/lib/i18n';
import type { Page } from '@inertiajs/core';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/** The page's language and sentences, before anything is drawn and after every visit. */
function language(page: Page) {
    const props = page.props as {
        locale?: string;
        translations?: Bundle;
    };

    setLanguage(props.locale ?? 'en', props.translations);
}

router.on('navigate', (event) => language(event.detail.page));

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
    withApp: (app, { page }) => {
        language(page);

        return app;
    },
});
