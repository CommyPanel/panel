import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { LanguageSelect } from '@/components/language-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/hooks/use-t';
import AuthLayout from '@/layouts/auth-layout';
import { fortify } from '@/lib/fortify';

export default function Login() {
    const { t, locale } = useT();
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(fortify.login, {
            onFinish: () => form.reset('password'),
        });
    };

    // Before signing in, the language is the browser's, and can be changed here for this session.
    const chooseLanguage = (code: string) =>
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

    return (
        <AuthLayout
            title={t('Log in')}
            description={t('Sign in to manage your server.')}
        >
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label={t('Email')}
                    type="email"
                    autoComplete="username"
                    autoFocus
                    required
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                />
                <Input
                    label={t('Password')}
                    type="password"
                    autoComplete="current-password"
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="checkbox checkbox-sm checkbox-primary"
                        checked={form.data.remember}
                        onChange={(e) =>
                            form.setData('remember', e.target.checked)
                        }
                    />
                    {t('Remember me')}
                </label>
                <Button
                    type="submit"
                    className="w-full"
                    loading={form.processing}
                >
                    {t('Log in')}
                </Button>
                <LanguageSelect value={locale} onChange={chooseLanguage} />
            </form>
        </AuthLayout>
    );
}
