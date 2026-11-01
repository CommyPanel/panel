import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';

type Props = { slug: string; open: boolean; onClose: () => void };

export function DeleteAppModal({ slug, open, onClose }: Props) {
    const form = useForm({ confirm: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppController.destroy(slug));
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={`Delete ${slug}?`}
            size="sm"
        >
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm">
                    This stops the app, removes its containers and images and
                    deletes its settings, variables and the files in its
                    persistent volumes. It cannot be undone.
                </p>
                <Input
                    label={`Type ${slug} to confirm`}
                    autoComplete="off"
                    autoFocus
                    value={form.data.confirm}
                    onChange={(e) => form.setData('confirm', e.target.value)}
                    error={form.errors.confirm}
                />
                <div className="flex justify-end gap-2">
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="error"
                        loading={form.processing}
                        disabled={form.data.confirm !== slug}
                    >
                        Delete app
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
