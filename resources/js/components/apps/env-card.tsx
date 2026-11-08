import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import EnvVarController from '@/actions/App/Http/Controllers/EnvVarController';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useT } from '@/hooks/use-t';

type Props = { slug: string; keys: string[] };

export function EnvCard({ slug, keys }: Props) {
    const { t } = useT();
    const form = useForm({ key: '', value: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(EnvVarController.store(slug), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const remove = (key: string) =>
        router.delete(EnvVarController.destroy({ app: slug, key }), {
            preserveScroll: true,
        });

    return (
        <Card
            title={t('Environment variables')}
            description={t(
                'Values are stored encrypted and never shown again. Changes reach the running app when you apply them (a button appears on the app) or deploy. PORT is set for you.',
            )}
        >
            {keys.length > 0 && (
                <ul className="divide-y divide-base-300 rounded-field border border-base-300">
                    {keys.map((key) => (
                        <li
                            key={key}
                            className="flex items-center justify-between gap-2 py-1 pr-1 pl-3"
                        >
                            <code className="truncate text-sm">{key}</code>
                            <Button
                                variant="ghost"
                                size="sm"
                                aria-label={t('Remove :name', { name: key })}
                                onClick={() => remove(key)}
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label={t('Name')}
                    placeholder="DATABASE_URL"
                    required
                    value={form.data.key}
                    onChange={(e) => form.setData('key', e.target.value)}
                    error={form.errors.key}
                />
                <Input
                    label={t('Value')}
                    type="password"
                    autoComplete="off"
                    value={form.data.value}
                    onChange={(e) => form.setData('value', e.target.value)}
                    error={form.errors.value}
                />
                <Button type="submit" loading={form.processing}>
                    {t('Save variable')}
                </Button>
            </form>
        </Card>
    );
}
