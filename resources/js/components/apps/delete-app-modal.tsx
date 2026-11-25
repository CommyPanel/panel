import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { useT } from '@/hooks/use-t';

type Props = {
    slug: string;
    open: boolean;
    onClose: () => void;
    /** Database servers made for this app: they are kept when the app is deleted. */
    dedicatedServers?: string[];
};

export function DeleteAppModal({
    slug,
    open,
    onClose,
    dedicatedServers = [],
}: Props) {
    const { t } = useT();
    const form = useForm({ confirm: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppController.destroy(slug));
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={t('Delete :name?', { name: slug })}
            size="sm"
        >
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm">
                    {t(
                        'This stops the app, removes its containers and images and deletes its settings, variables and the files in its persistent volumes. It cannot be undone.',
                    )}
                </p>
                {dedicatedServers.length > 0 && (
                    <p className="text-sm">
                        {t(
                            'The database server made for this app (:servers) is not deleted. It stays, with all its data, as an ordinary server under Databases.',
                            { servers: dedicatedServers.join(', ') },
                        )}
                    </p>
                )}
                <Input
                    label={t('Type :name to confirm', { name: slug })}
                    autoComplete="off"
                    autoFocus
                    value={form.data.confirm}
                    onChange={(e) => form.setData('confirm', e.target.value)}
                    error={form.errors.confirm}
                />
                <div className="flex justify-end gap-2">
                    <Button variant="ghost" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="submit"
                        variant="error"
                        loading={form.processing}
                        disabled={form.data.confirm !== slug}
                    >
                        {t('Delete app')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
