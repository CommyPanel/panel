import { Check, Languages } from 'lucide-react';
import { useLocales } from '@/components/language-select';
import { Dropdown, DropdownItem } from '@/components/ui/dropdown';
import { useChangeLanguage } from '@/hooks/use-language';
import { useT } from '@/hooks/use-t';

/** The top bar's language menu: each language in its own name, the current one ticked. */
export function LanguageMenu() {
    const { t, locale } = useT();
    const locales = useLocales();
    const change = useChangeLanguage();

    return (
        <Dropdown
            label={t('Change language')}
            trigger={<Languages className="size-5" />}
        >
            {(close) =>
                locales.map((option) => (
                    <DropdownItem
                        key={option.code}
                        radio
                        selected={locale === option.code}
                        onSelect={() => {
                            close();
                            if (option.code !== locale) change(option.code);
                        }}
                    >
                        <span className="flex-1" lang={option.code}>
                            {option.name}
                        </span>
                        {locale === option.code && <Check className="size-4" />}
                    </DropdownItem>
                ))
            }
        </Dropdown>
    );
}
