import { router, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
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

type Row = { key: string; value: string };

type Props = { slug: string; keys: string[] };

export function EnvCard({ slug, keys }: Props) {
    const { t } = useT();
    const form = useForm({ key: '', value: '' });
    const confirmed = useConfirmedAction();
    const [clearing, setClearing] = useState(false);
    const [editing, setEditing] = useState<Row[] | null>(null);
    const [original, setOriginal] = useState<Row[]>([]);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const startEdit = () =>
        void confirmed.run(async () => {
            setLoadError(null);

            try {
                const response = await fetch(
                    EnvVarController.reveal(slug).url,
                    {
                        credentials: 'same-origin',
                        headers: jsonHeaders(),
                    },
                );

                if (!response.ok) {
                    throw new Error('failed');
                }

                const body = (await response.json()) as { vars: Row[] };
                setOriginal(body.vars);
                setEditing(body.vars.map((row) => ({ ...row })));
            } catch {
                setLoadError(
                    t('The variables could not be loaded. Try again.'),
                );
            }
        });

    const stopEdit = () => {
        // Drop the values from memory as soon as the dialog closes.
        setEditing(null);
        setOriginal([]);
    };

    const save = () => {
        if (editing === null) {
            return;
        }

        const changed: Record<string, string> = {};

        editing.forEach((row, index) => {
            if (row.value !== original[index]?.value) {
                changed[row.key] = row.value;
            }
        });

        if (Object.keys(changed).length === 0) {
            stopEdit();

            return;
        }

        void confirmed.run(() =>
            router.put(
                EnvVarController.update(slug),
                { vars: changed },
                {
                    preserveScroll: true,
                    onStart: () => setSaving(true),
                    onFinish: () => setSaving(false),
                    onSuccess: stopEdit,
                },
            ),
        );
    };

    const clearAll = () =>
        router.delete(EnvVarController.clear(slug), {
            preserveScroll: true,
            onSuccess: () => setClearing(false),
        });

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
            {loadError && <Alert variant="error">{loadError}</Alert>}
            {keys.length > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-sm text-base-content/70">
                        {t(':count variable(s)', { count: keys.length })}
                    </span>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="soft" size="sm" onClick={startEdit}>
                            <Pencil className="size-4" aria-hidden />
                            {t('View and edit values')}
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
                onClose={stopEdit}
                title={t('Environment variables')}
                size="lg"
                footer={
                    <>
                        <Button variant="ghost" onClick={stopEdit}>
                            {t('Cancel')}
                        </Button>
                        <Button loading={saving} onClick={save}>
                            {t('Save changes')}
                        </Button>
                    </>
                }
            >
                <div className="space-y-3">
                    <p className="text-sm text-base-content/70">
                        {t(
                            'Change a value and save. Names stay as they are; to add a variable use the form below the list. Changes reach the running app when you apply them.',
                        )}
                    </p>
                    {(editing ?? []).map((row, index) => (
                        <div key={row.key} className="w-full">
                            <label
                                className="mb-1 label-text block px-0!"
                                htmlFor={`env-edit-${index}`}
                            >
                                {row.key}
                            </label>
                            <textarea
                                id={`env-edit-${index}`}
                                className="textarea w-full font-mono text-sm"
                                rows={row.value.includes('\n') ? 4 : 1}
                                autoComplete="off"
                                spellCheck={false}
                                value={row.value}
                                onChange={(e) =>
                                    setEditing((rows) =>
                                        (rows ?? []).map((r, i) =>
                                            i === index
                                                ? {
                                                      ...r,
                                                      value: e.target.value,
                                                  }
                                                : r,
                                        ),
                                    )
                                }
                            />
                        </div>
                    ))}
                </div>
            </Modal>
        </Card>
    );
}
