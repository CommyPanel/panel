import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ManagedDatabaseController from '@/actions/App/Http/Controllers/ManagedDatabaseController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { useT } from '@/hooks/use-t';
import { Trans } from '@/lib/trans';

export function AddDatabaseModal({
    slug,
    open,
    onClose,
}: {
    slug: string;
    open: boolean;
    onClose: () => void;
}) {
    const { t } = useT();
    const form = useForm({ name: '' });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ManagedDatabaseController.store(slug), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    return (
        <Modal open={open} onClose={close} title={t('Add a database')}>
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label={t('Database name')}
                    placeholder={t('shop')}
                    required
                    autoFocus
                    autoComplete="off"
                    spellCheck={false}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={form.errors.name}
                    hint={t(
                        'Lowercase letters, digits and underscores, starting with a letter.',
                    )}
                />
                <ul className="list-disc space-y-1.5 pl-5 text-sm text-base-content/80">
                    <li>
                        {t(
                            'A user with the same name is created. It can use this database and nothing else on the server.',
                        )}
                    </li>
                    <li>
                        {t(
                            'A long random password is generated and kept encrypted. You can show it later, after confirming your password.',
                        )}
                    </li>
                    <li>
                        <Trans
                            text="To use the database, link it to an app on that app's <b>Links</b> tab."
                            components={{ b: <strong /> }}
                        />
                    </li>
                </ul>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        {t('Add database')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
