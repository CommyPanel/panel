import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { LanguageSelect } from '@/components/language-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useChangeLanguage } from '@/hooks/use-language';
import { useT } from '@/hooks/use-t';
import SetupLayout from '@/layouts/setup-layout';

type Props = { token: string };

export default function Setup({ token }: Props) {
    const { t, locale } = useT();
    const form = useForm({
        locale,
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    // Choosing a language shows this page in it at once, and it becomes the admin's language.
    const changeLanguage = useChangeLanguage();
    const chooseLanguage = (code: string) => {
        form.setData('locale', code);
        changeLanguage(code);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/setup/${token}`, {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <SetupLayout
            title={t('Set up CommyPanel')}
            description={t(
                'Welcome. A few steps to get your server ready, starting with the first admin account.',
            )}
            step={0}
        >
            <Card
                title={t('Create the admin account')}
                description={t(
                    'This account can do everything, and it cannot be removed from inside the panel. The link you used works only once.',
                )}
            >
                <form onSubmit={submit} className="space-y-4">
                    <LanguageSelect
                        value={form.data.locale}
                        onChange={chooseLanguage}
                        error={form.errors.locale}
                        hint={t(
                            'The language of your panel. You can change it later, and everyone you add chooses their own.',
                        )}
                    />
                    <Input
                        label={t('Name')}
                        autoComplete="name"
                        autoFocus
                        required
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />
                    <Input
                        label={t('Email')}
                        type="email"
                        autoComplete="username"
                        required
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        error={form.errors.email}
                    />
                    <Input
                        label={t('Password')}
                        type="password"
                        autoComplete="new-password"
                        required
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData('password', e.target.value)
                        }
                        error={form.errors.password}
                    />
                    <Input
                        label={t('Confirm password')}
                        type="password"
                        autoComplete="new-password"
                        required
                        value={form.data.password_confirmation}
                        onChange={(e) =>
                            form.setData(
                                'password_confirmation',
                                e.target.value,
                            )
                        }
                        error={form.errors.password_confirmation}
                    />
                    <Button
                        type="submit"
                        className="w-full"
                        loading={form.processing}
                    >
                        {t('Create admin')}
                    </Button>
                </form>
            </Card>
        </SetupLayout>
    );
}
