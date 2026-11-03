import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card } from '@/components/ui/card';
import SetupLayout from '@/layouts/setup-layout';

type Props = { token: string };

export default function Setup({ token }: Props) {
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/setup/${token}`, {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <SetupLayout
            title="Set up CommyPanel"
            description="Welcome. A few steps to get your server ready, starting with the first admin account."
            step={0}
        >
            <Card
                title="Create the admin account"
                description="This account can do everything, and it cannot be removed from inside the panel. The link you used works only once."
            >
                <form onSubmit={submit} className="space-y-4">
                    <Input
                        label="Name"
                        autoComplete="name"
                        autoFocus
                        required
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />
                    <Input
                        label="Email"
                        type="email"
                        autoComplete="username"
                        required
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        error={form.errors.email}
                    />
                    <Input
                        label="Password"
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
                        label="Confirm password"
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
                        Create admin
                    </Button>
                </form>
            </Card>
        </SetupLayout>
    );
}
