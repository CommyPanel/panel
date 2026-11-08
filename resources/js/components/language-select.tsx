import { usePage } from '@inertiajs/react';
import { Select } from '@/components/ui/select';
import { useT } from '@/hooks/use-t';

export type LocaleOption = { code: string; name: string };

/** The languages the panel is available in, each in its own name. */
export function useLocales(): LocaleOption[] {
    return usePage<{ locales?: LocaleOption[] }>().props.locales ?? [];
}

/**
 * A select of the panel's languages. Used wherever a person chooses one: the first setup page,
 * the login page, a new user and the account's own settings.
 */
export function LanguageSelect({
    value,
    onChange,
    label,
    hint,
    error,
}: {
    value: string;
    onChange: (code: string) => void;
    label?: string;
    hint?: string;
    error?: string;
}) {
    const { t } = useT();
    const locales = useLocales();

    return (
        <Select
            label={label ?? t('Language')}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            hint={hint}
            error={error}
        >
            {locales.map((locale) => (
                <option
                    key={locale.code}
                    value={locale.code}
                    lang={locale.code}
                >
                    {locale.name}
                </option>
            ))}
        </Select>
    );
}
