import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/hooks/use-t';
import AuthLayout from '@/layouts/auth-layout';
import { fortify } from '@/lib/fortify';

export default function TwoFactorChallenge() {
    const { t } = useT();
    const [useRecovery, setUseRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(fortify.twoFactorChallenge);
    };

    const toggle = () => {
        setUseRecovery((value) => !value);
        form.reset();
        form.clearErrors();
    };

    return (
        <AuthLayout
            title={t('Two-factor authentication')}
            description={
                useRecovery
                    ? t('Enter one of your emergency recovery codes.')
                    : t('Enter the code from your authenticator app.')
            }
        >
            <form onSubmit={submit} className="space-y-4">
                {useRecovery ? (
                    <Input
                        key="recovery"
                        label={t('Recovery code')}
                        autoComplete="one-time-code"
                        autoFocus
                        required
                        value={form.data.recovery_code}
                        onChange={(e) =>
                            form.setData('recovery_code', e.target.value)
                        }
                        error={form.errors.recovery_code}
                    />
                ) : (
                    <Input
                        key="code"
                        label={t('Authentication code')}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        autoFocus
                        required
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value)}
                        error={form.errors.code}
                    />
                )}
                <Button
                    type="submit"
                    className="w-full"
                    loading={form.processing}
                >
                    {t('Continue')}
                </Button>
                <Button variant="ghost" className="w-full" onClick={toggle}>
                    {useRecovery
                        ? t('Use an authentication code')
                        : t('Use a recovery code')}
                </Button>
            </form>
        </AuthLayout>
    );
}
