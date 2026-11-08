export type ThemePreference = 'system' | 'light' | 'dim' | 'dark';

/** The theme choices with their names; `t` is the component's translate function. */
export const themeOptions = (
    t: (key: string) => string,
): {
    value: ThemePreference;
    label: string;
    description: string;
}[] => [
    {
        value: 'system',
        label: t('System'),
        description: t('Light by day, dim at night, following your device.'),
    },
    { value: 'light', label: t('Light'), description: t('Warm and bright.') },
    {
        value: 'dim',
        label: t('Dim'),
        description: t('A softer dark, easy on the eyes.'),
    },
    {
        value: 'dark',
        label: t('Dark'),
        description: t('True black, like "lights out".'),
    },
];

const STORAGE_KEY = 'commypanel-theme';
const EVENT = 'commypanel-theme-change';

const DATA_THEMES = {
    light: 'commy-light',
    dim: 'commy-dim',
    dark: 'commy-dark',
} as const;

export function readPreference(): ThemePreference {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        if (
            stored === 'light' ||
            stored === 'dim' ||
            stored === 'dark' ||
            stored === 'system'
        ) {
            return stored;
        }
    } catch {
        // Storage can be blocked; fall back to following the system.
    }

    return 'system';
}

export function resolveTheme(preference: ThemePreference): string {
    if (preference !== 'system') return DATA_THEMES[preference];

    return window.matchMedia('(prefers-color-scheme: dark)').matches
        ? DATA_THEMES.dim
        : DATA_THEMES.light;
}

export function applyPreference(preference: ThemePreference): void {
    document.documentElement.setAttribute(
        'data-theme',
        resolveTheme(preference),
    );
}

export function savePreference(preference: ThemePreference): void {
    try {
        localStorage.setItem(STORAGE_KEY, preference);
    } catch {
        // The choice still applies for this page view.
    }

    applyPreference(preference);
    window.dispatchEvent(new Event(EVENT));
}

export function subscribeToPreference(onChange: () => void): () => void {
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const handleSystemChange = () => {
        if (readPreference() === 'system') applyPreference('system');
        onChange();
    };

    window.addEventListener(EVENT, onChange);
    window.addEventListener('storage', handleSystemChange);
    media.addEventListener('change', handleSystemChange);

    return () => {
        window.removeEventListener(EVENT, onChange);
        window.removeEventListener('storage', handleSystemChange);
        media.removeEventListener('change', handleSystemChange);
    };
}
