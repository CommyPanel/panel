import { router } from '@inertiajs/react';
import { useState } from 'react';
import LanguageController from '@/actions/App/Http/Controllers/LanguageController';
import { LanguageSelect } from '@/components/language-select';
import { PageHeader } from '@/components/page-header';
import { ThemePicker } from '@/components/theme-picker';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';

export default function Appearance() {
    const { t, locale } = useT();
    const [value, setValue] = useState(locale);

    const change = (code: string) => {
        setValue(code);
        router.put(
            LanguageController.update().url,
            { locale: code },
            {
                preserveScroll: true,
                // Every sentence of the page changes: start the page over in the new language.
                onSuccess: () => window.location.reload(),
            },
        );
    };

    return (
        <AppLayout title={t('Appearance')}>
            <PageHeader
                title={t('Appearance')}
                description={t(
                    'Pick how CommyPanel looks and which language it speaks.',
                )}
            />
            <div className="space-y-6">
                <Card
                    title={t('Language')}
                    description={t(
                        'The language of the panel for your account. Other people choose their own.',
                    )}
                >
                    <LanguageSelect value={value} onChange={change} />
                </Card>
                <Card
                    title={t('Theme')}
                    description={t('The choice is remembered in this browser.')}
                >
                    <ThemePicker />
                </Card>
            </div>
        </AppLayout>
    );
}
