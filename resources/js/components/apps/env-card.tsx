import { router, useForm } from '@inertiajs/react';
import { Download, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import EnvVarController from '@/actions/App/Http/Controllers/EnvVarController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import { jsonHeaders } from '@/lib/post-json';

type Editing = { key: string; value: string };

type Props = { slug: string; keys: string[] };

export function EnvCard({ slug, keys }: Props) {
    const { t } = useT();
    const form = useForm({ key: '', value: '' });
    const confirmed = useConfirmedAction();
    const [clearing, setClearing] = useState(false);
    const [editing, setEditing] = useState<Editing | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

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

    // The value is fetched only now, after a password confirmation, and dropped again when the
    // dialog closes. It is never part of the page.
    const startEdit = (key: string) =>
        void confirmed.run(async () => {
            setLoadError(null);

            try {
                const response = await fetch(
                    EnvVarController.reveal({ app: slug, key }).url,
                    { credentials: 'same-origin', headers: jsonHeaders() },
                );

                if (!response.ok) {
                    throw new Error('failed');
                }

                setEditing((await response.json()) as Editing);
            } catch {
                setLoadError(t('The variable could not be loaded. Try again.'));
            }
        });

    const save = () => {
        if (editing === null) {
            return;
        }

        void confirmed.run(() =>
            router.put(
                EnvVarController.update({ app: slug, key: editing.key }),
                { value: editing.value },
                {
                    preserveScroll: true,
                    onStart: () => setSaving(true),
                    onFinish: () => setSaving(false),
                    onSuccess: () => setEditing(null),
                },
            ),
        );
    };

    const download = () =>
        void confirmed.run(() => {
            window.location.assign(EnvVarController.download(slug).url);
        });

    const clearAll = () => {
        setClearing(false);
        router.delete(EnvVarController.clear(slug), { preserveScroll: true });
    };

    return (
        <Card
            title={t('Environment variables')}
            description={t(
                'Values are stored encrypted and are only shown after you confirm your password. Changes reach the running app when you apply them (a button appears on the app) or deploy. PORT is set for you.',
            )}
        >
            {loadError && <Alert variant="error">{loadError}</Alert>}
            {keys.length > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-sm text-base-content/70">
                        {t(':count variable(s)', { count: keys.length })}
                    </span>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="soft" size="sm" onClick={download}>
                            <Download className="size-4" aria-hidden />
                            {t('Download as .env file')}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setClearing(true)}
                        >
                            <Trash2 className="size-4" aria-hidden />
                            {t('Delete all')}
                        </Button>
                    </div>
                </div>
            )}
            {keys.length > 0 && (
                <ul className="divide-y divide-base-300 rounded-field border border-base-300">
                    {keys.map((key) => (
                        <li
                            key={key}
                            className="flex items-center justify-between gap-2 py-1 pr-1 pl-3"
                        >
                            <code className="truncate text-sm">{key}</code>
                            <div className="flex shrink-0">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={t('Edit :name', { name: key })}
                                    onClick={() => startEdit(key)}
                                >
                                    <Pencil className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={t('Remove :name', {
                                        name: key,
                                    })}
                                    onClick={() => remove(key)}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
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
            {confirmed.modal}
            <ConfirmModal
                open={clearing}
                title={t('Delete all variables?')}
                confirmLabel={t('Delete all')}
                onConfirm={clearAll}
                onClose={() => setClearing(false)}
            >
                <p className="text-sm text-base-content/80">
                    {t(
                        'This removes all :count variables of this app. The running app keeps using them until you apply the changes or deploy, and the values cannot be brought back.',
                        { count: keys.length },
                    )}
                </p>
            </ConfirmModal>
            <Modal
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={t('Edit :name', { name: editing?.key ?? '' })}
                size="md"
                footer={
                    <>
                        <Button
                            variant="ghost"
                            onClick={() => setEditing(null)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button loading={saving} onClick={save}>
                            {t('Save changes')}
                        </Button>
                    </>
                }
            >
                <div className="w-full">
                    <label
                        className="mb-1 label-text block px-0!"
                        htmlFor="env-edit-value"
                    >
                        {t('Value')}
                    </label>
                    <textarea
                        id="env-edit-value"
                        className="textarea w-full font-mono text-sm"
                        rows={editing?.value.includes('\n') ? 6 : 2}
                        autoComplete="off"
                        spellCheck={false}
                        autoFocus
                        value={editing?.value ?? ''}
                        onChange={(e) =>
                            setEditing((row) =>
                                row === null
                                    ? null
                                    : { ...row, value: e.target.value },
                            )
                        }
                    />
                    <span className="mt-1 block text-sm text-base-content/60">
                        {t(
                            'The change reaches the running app when you apply it.',
                        )}
                    </span>
                </div>
            </Modal>
        </Card>
    );
}
