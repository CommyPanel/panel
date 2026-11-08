import { useState } from 'react';
import { LanguageSelect } from '@/components/language-select';
import { PageHeader } from '@/components/page-header';
import { ThemePicker } from '@/components/theme-picker';
import { Card } from '@/components/ui/card';
import { useChangeLanguage } from '@/hooks/use-language';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';

export default function Appearance() {
    const { t, locale } = useT();
    const [value, setValue] = useState(locale);

    const changeLanguage = useChangeLanguage();

    const change = (code: string) => {
        setValue(code);
        changeLanguage(code);
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
