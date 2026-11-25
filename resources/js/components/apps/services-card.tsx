import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import AppProcessController from '@/actions/App/Http/Controllers/AppProcessController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';
import type { AppProcess } from '@/types';

type Props = {
    slug: string;
    processes: AppProcess[];
    /** The page of an existing app, or a step of the creation wizard. */
    variant?: 'page' | 'wizard';
    /** Called after the list was saved (the wizard goes on to its next step). */
    onSaved?: () => void;
    /** Tells a parent whether there are unsaved changes (the wizard waits for them to be saved). */
    onDirtyChange?: (dirty: boolean) => void;
};

type Row = {
    name: string;
    kind: AppProcess['kind'];
    command: string;
    enabled: boolean;
    replicas: string;
    overlap: AppProcess['overlap'];
    memory_limit_mb: string;
    stop_grace_seconds: string;
};

const toRow = (p: AppProcess): Row => ({
    name: p.name,
    kind: p.kind,
    command: p.command ?? '',
    enabled: p.enabled,
    replicas: String(p.replicas),
    overlap: p.overlap,
    memory_limit_mb: p.memory_limit_mb?.toString() ?? '',
    stop_grace_seconds: String(p.stop_grace_seconds),
});

/**
 * The extra processes of an app that run beside the web one (a Laravel app: the scheduler, queue workers,
 * Horizon ...). Each is a container from the same image with its own command. Services found in the code
 * start switched off. They apply from the next deployment or with "Apply changes".
 */
export function ServicesCard({
    slug,
    processes,
    variant = 'page',
    onSaved,
    onDirtyChange,
}: Props) {
    const { t } = useT();
    const form = useForm<{ processes: Row[] }>({
        processes: processes.map(toRow),
    });
    const [added, setAdded] = useState(0);
    const dirty = form.isDirty;

    useEffect(() => {
        onDirtyChange?.(dirty);
    }, [dirty]); // eslint-disable-line react-hooks/exhaustive-deps
    const byName = new Map(processes.map((p) => [p.name, p]));

    const update = (index: number, change: Partial<Row>) =>
        form.setData(
            'processes',
            form.data.processes.map((row, i) =>
                i === index ? { ...row, ...change } : row,
            ),
        );

    const remove = (index: number) =>
        form.setData(
            'processes',
            form.data.processes.filter((_, i) => i !== index),
        );

    const add = () => {
        form.setData('processes', [
            ...form.data.processes,
            {
                name: added === 0 ? 'worker' : `worker-${added + 1}`,
                kind: 'custom',
                command: '',
                enabled: true,
                replicas: '1',
                overlap: 'overlap',
                memory_limit_mb: '',
                stop_grace_seconds: '30',
            },
        ]);
        setAdded(added + 1);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppProcessController.update(slug), {
            preserveScroll: true,
            onSuccess: () => onSaved?.(),
        });
    };

    const error = (index: number, field: string) =>
        (form.errors as Record<string, string | undefined>)[
            `processes.${index}.${field}`
        ];

    return (
        <Card
            title={variant === 'page' ? t('Services') : undefined}
            description={t(
                'Processes that run beside the web one, each in its own container from the same release. The scheduler runs your scheduled tasks; the others start switched off. Change a command if yours differs.',
            )}
        >
            <form onSubmit={submit} className="space-y-4">
                {form.errors.processes && (
                    <Alert variant="error">{form.errors.processes}</Alert>
                )}

                <ul className="divide-y divide-base-300 rounded-field border border-base-300">
                    {form.data.processes.map((row, index) => {
                        const known = byName.get(row.name);
                        const unsupported = known?.supported === false;
                        const custom = row.kind === 'custom';
                        const usual =
                            known?.default_command ??
                            (row.kind === 'custom'
                                ? ''
                                : t('the usual command'));
                        const id = `service-${index}`;

                        return (
                            <li key={index} className="space-y-3 p-4">
                                <div className="flex flex-wrap items-center gap-3">
                                    <input
                                        id={`${id}-on`}
                                        type="checkbox"
                                        className="toggle toggle-primary"
                                        checked={row.enabled}
                                        disabled={unsupported && !row.enabled}
                                        onChange={(e) =>
                                            update(index, {
                                                enabled: e.target.checked,
                                            })
                                        }
                                        aria-label={t('Run :name', {
                                            name: row.name,
                                        })}
                                    />
                                    {custom && !known ? (
                                        <input
                                            className="input input-sm w-48 font-mono"
                                            value={row.name}
                                            onChange={(e) =>
                                                update(index, {
                                                    name: e.target.value,
                                                })
                                            }
                                            aria-label={t('Name')}
                                            spellCheck={false}
                                        />
                                    ) : (
                                        <label
                                            htmlFor={`${id}-on`}
                                            className="font-medium"
                                        >
                                            {known?.kind_label ?? row.name}
                                            {custom && known && (
                                                <code className="ml-2 text-xs font-normal text-base-content/70">
                                                    {row.name}
                                                </code>
                                            )}
                                        </label>
                                    )}
                                    {known?.detected_by && (
                                        <span className="badge-soft badge-neutral badge badge-sm">
                                            {t('found: :package', {
                                                package: known.detected_by,
                                            })}
                                        </span>
                                    )}
                                    {row.overlap === 'stop_first' && (
                                        <span
                                            className="text-xs text-base-content/70"
                                            title={t(
                                                'The old copy stops before the new one starts, so it never runs twice.',
                                            )}
                                        >
                                            {t('one copy at a time')}
                                        </span>
                                    )}
                                    {(known?.removable ?? true) && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="ml-auto"
                                            aria-label={t('Remove :name', {
                                                name: row.name,
                                            })}
                                            onClick={() => remove(index)}
                                        >
                                            <Trash2
                                                className="size-4"
                                                aria-hidden
                                            />
                                        </Button>
                                    )}
                                </div>

                                {unsupported && known?.note && (
                                    <Alert variant="warning">
                                        {known.note}
                                    </Alert>
                                )}
                                {error(index, 'name') && (
                                    <Alert variant="error">
                                        {error(index, 'name')}
                                    </Alert>
                                )}

                                <Input
                                    label={t('Command')}
                                    className="font-mono"
                                    placeholder={usual}
                                    value={row.command}
                                    onChange={(e) =>
                                        update(index, {
                                            command: e.target.value,
                                        })
                                    }
                                    error={error(index, 'command')}
                                    spellCheck={false}
                                    autoComplete="off"
                                    hint={
                                        row.command === '' && usual !== ''
                                            ? t(
                                                  "Empty runs the usual command shown here. It runs without a shell, in the app's folder.",
                                              )
                                            : undefined
                                    }
                                />

                                <details className="group">
                                    <summary
                                        className={cn(
                                            'cursor-pointer text-sm text-base-content/70',
                                            'hover:text-base-content',
                                        )}
                                    >
                                        {t('More settings')}
                                    </summary>
                                    <div className="mt-3 space-y-3">
                                        {(known?.may_replicate ??
                                            row.kind === 'custom') && (
                                            <Input
                                                label={t('Copies')}
                                                type="number"
                                                min={1}
                                                max={10}
                                                value={row.replicas}
                                                onChange={(e) =>
                                                    update(index, {
                                                        replicas:
                                                            e.target.value,
                                                    })
                                                }
                                                error={error(index, 'replicas')}
                                                hint={t(
                                                    'How many copies run at the same time.',
                                                )}
                                            />
                                        )}
                                        <Select
                                            label={t('Replacing a release')}
                                            value={row.overlap}
                                            onChange={(e) =>
                                                update(index, {
                                                    overlap: e.target
                                                        .value as Row['overlap'],
                                                })
                                            }
                                            error={error(index, 'overlap')}
                                            hint={t(
                                                'A copy that may run beside the old one starts before traffic moves. One that must run alone is stopped first and the new one starts after traffic moved.',
                                            )}
                                        >
                                            <option value="overlap">
                                                {t(
                                                    'May run beside the old one',
                                                )}
                                            </option>
                                            <option value="stop_first">
                                                {t('Stop the old one first')}
                                            </option>
                                        </Select>
                                        <Input
                                            label={t(
                                                'Time to finish its work when stopped (seconds)',
                                            )}
                                            type="number"
                                            min={1}
                                            max={600}
                                            value={row.stop_grace_seconds}
                                            onChange={(e) =>
                                                update(index, {
                                                    stop_grace_seconds:
                                                        e.target.value,
                                                })
                                            }
                                            error={error(
                                                index,
                                                'stop_grace_seconds',
                                            )}
                                        />
                                        <Input
                                            label={t('Memory limit (MB)')}
                                            type="number"
                                            min={16}
                                            placeholder={t('Same as the app')}
                                            value={row.memory_limit_mb}
                                            onChange={(e) =>
                                                update(index, {
                                                    memory_limit_mb:
                                                        e.target.value,
                                                })
                                            }
                                            error={error(
                                                index,
                                                'memory_limit_mb',
                                            )}
                                        />
                                    </div>
                                </details>
                            </li>
                        );
                    })}
                </ul>

                {form.data.processes.length === 0 && (
                    <p className="text-sm text-base-content/70">
                        {t(
                            'No extra processes. The app runs its web process only.',
                        )}
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="button" variant="soft" onClick={add}>
                        <Plus className="size-4" aria-hidden />
                        {t('Add a process')}
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        {t('Save services')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}
