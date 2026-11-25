import { useForm } from '@inertiajs/react';
import {
    Activity,
    ChevronDown,
    Clock,
    Globe,
    HeartPulse,
    Info,
    Layers,
    Plus,
    Server,
    Terminal,
    Trash2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import AppProcessController from '@/actions/App/Http/Controllers/AppProcessController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tooltip } from '@/components/ui/tooltip';
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

const ICONS: Record<AppProcess['kind'], LucideIcon> = {
    scheduler: Clock,
    queue: Layers,
    horizon: Activity,
    pulse: HeartPulse,
    ssr: Globe,
    custom: Terminal,
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
    const [open, setOpen] = useState<Record<number, boolean>>({});
    const dirty = form.isDirty;

    useEffect(() => {
        onDirtyChange?.(dirty);
    }, [dirty]); // eslint-disable-line react-hooks/exhaustive-deps
    const byName = new Map(processes.map((p) => [p.name, p]));
    const enabledCount = form.data.processes.filter((r) => r.enabled).length;

    const describe = (kind: AppProcess['kind']) =>
        ({
            scheduler: t('Runs your scheduled tasks every minute.'),
            queue: t('Works through the jobs your app puts on its queue.'),
            horizon: t('Runs and watches your Redis queues with a dashboard.'),
            pulse: t('Collects the numbers shown on the Pulse dashboard.'),
            ssr: t('Renders your Inertia pages on the server.'),
            custom: t('A process of your own, started with the command below.'),
        })[kind];

    const update = (index: number, change: Partial<Row>) =>
        form.setData(
            'processes',
            form.data.processes.map((row, i) =>
                i === index ? { ...row, ...change } : row,
            ),
        );

    const remove = (index: number) => {
        form.setData(
            'processes',
            form.data.processes.filter((_, i) => i !== index),
        );
        setOpen({});
    };

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
        // The new service opens its editor at once, so it can be named and given a command.
        setOpen({ ...open, [form.data.processes.length]: true });
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
            description={
                variant === 'page'
                    ? t(
                          'Processes that run beside the web one, each in its own container from the same release. The scheduler runs your scheduled tasks; the others start switched off. Change a command if yours differs.',
                      )
                    : undefined
            }
        >
            <form onSubmit={submit} className="space-y-4">
                {form.errors.processes && (
                    <Alert variant="error">{form.errors.processes}</Alert>
                )}

                <p className="text-sm text-base-content/70" aria-live="polite">
                    {t(':on of :total services are on', {
                        on: String(enabledCount),
                        total: String(form.data.processes.length),
                    })}
                </p>

                <ul className="space-y-3">
                    <li className="flex items-center gap-3 rounded-box border border-primary/40 bg-base-100 p-4">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-box bg-primary/10 text-primary">
                            <Server className="size-5" aria-hidden />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="font-medium">{t('Web server')}</p>
                            <p className="text-sm text-base-content/70">
                                {t('Answers your visitors. It always runs.')}
                            </p>
                        </div>
                        <span className="badge-soft badge badge-sm badge-primary">
                            {t('Always on')}
                        </span>
                    </li>

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
                        const on = row.enabled;
                        const expanded = on && (open[index] ?? false);
                        const Icon = ICONS[row.kind] ?? Terminal;
                        const shown = row.command || usual;

                        return (
                            <li
                                key={index}
                                className={cn(
                                    'rounded-box border p-4 transition-all duration-200 motion-reduce:transition-none',
                                    on
                                        ? 'border-primary/40 bg-base-100 shadow-xs'
                                        : 'border-base-300 bg-base-200/50 opacity-60 hover:opacity-80',
                                )}
                            >
                                <div className="flex items-start gap-3">
                                    <span
                                        className={cn(
                                            'flex size-10 shrink-0 items-center justify-center rounded-box transition-colors motion-reduce:transition-none',
                                            on
                                                ? 'bg-primary/10 text-primary'
                                                : 'bg-base-300/60 text-base-content/60',
                                        )}
                                    >
                                        <Icon className="size-5" aria-hidden />
                                    </span>
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            {custom && !known ? (
                                                <input
                                                    className="input input-sm w-44 font-mono"
                                                    value={row.name}
                                                    onChange={(e) =>
                                                        update(index, {
                                                            name: e.target
                                                                .value,
                                                        })
                                                    }
                                                    aria-label={t('Name')}
                                                    spellCheck={false}
                                                />
                                            ) : (
                                                <label
                                                    htmlFor={`${id}-on`}
                                                    className={cn(
                                                        'font-medium',
                                                        !unsupported &&
                                                            'cursor-pointer',
                                                    )}
                                                >
                                                    {known?.kind_label ??
                                                        row.name}
                                                    {custom && known && (
                                                        <code className="ml-2 text-xs font-normal text-base-content/70">
                                                            {row.name}
                                                        </code>
                                                    )}
                                                </label>
                                            )}
                                            {unsupported ? (
                                                <span className="badge-soft badge badge-sm badge-warning">
                                                    {t('Not supported yet')}
                                                </span>
                                            ) : (
                                                <span
                                                    className={cn(
                                                        'badge-soft badge badge-sm',
                                                        on
                                                            ? 'badge-success'
                                                            : 'badge-neutral',
                                                    )}
                                                >
                                                    {on ? t('On') : t('Off')}
                                                </span>
                                            )}
                                            {known?.detected_by && (
                                                <span className="badge-soft badge-neutral badge badge-sm">
                                                    {t(
                                                        'Detected in your code: :package',
                                                        {
                                                            package:
                                                                known.detected_by,
                                                        },
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-sm text-base-content/70">
                                            {describe(row.kind)}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-1">
                                        {(known?.removable ?? true) && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
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
                                        <Switch
                                            id={`${id}-on`}
                                            checked={on}
                                            disabled={unsupported && !on}
                                            label={t('Run :name', {
                                                name: row.name,
                                            })}
                                            onCheckedChange={(checked) => {
                                                update(index, {
                                                    enabled: checked,
                                                });
                                                if (!checked) {
                                                    setOpen({
                                                        ...open,
                                                        [index]: false,
                                                    });
                                                }
                                            }}
                                        />
                                    </div>
                                </div>

                                {unsupported && known?.note && (
                                    <div className="mt-3">
                                        <Alert variant="warning">
                                            {known.note}
                                        </Alert>
                                    </div>
                                )}
                                {error(index, 'name') && (
                                    <div className="mt-3">
                                        <Alert variant="error">
                                            {error(index, 'name')}
                                        </Alert>
                                    </div>
                                )}

                                {!unsupported && (
                                    <div className="mt-3 space-y-3 sm:pl-13">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <code
                                                className="min-w-0 flex-1 truncate rounded-field bg-base-200 px-2.5 py-1.5 font-mono text-xs"
                                                title={shown}
                                            >
                                                {shown || t('No command yet')}
                                            </code>
                                            {row.overlap === 'stop_first' && (
                                                <Tooltip
                                                    label={t(
                                                        'The old copy stops before the new one starts, so it never runs twice.',
                                                    )}
                                                    side="top"
                                                >
                                                    <span className="badge-soft badge-neutral badge gap-1 badge-sm">
                                                        <Info
                                                            className="size-3"
                                                            aria-hidden
                                                        />
                                                        {t(
                                                            'one copy at a time',
                                                        )}
                                                    </span>
                                                </Tooltip>
                                            )}
                                            <button
                                                type="button"
                                                disabled={!on}
                                                aria-expanded={expanded}
                                                aria-controls={`${id}-more`}
                                                onClick={() =>
                                                    setOpen({
                                                        ...open,
                                                        [index]: !expanded,
                                                    })
                                                }
                                                className="inline-flex items-center gap-1 rounded-field px-2 py-1 text-sm text-base-content/70 hover:text-base-content disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {t('Customize')}
                                                <ChevronDown
                                                    className={cn(
                                                        'size-4 transition-transform motion-reduce:transition-none',
                                                        expanded &&
                                                            'rotate-180',
                                                    )}
                                                    aria-hidden
                                                />
                                            </button>
                                        </div>

                                        {error(index, 'command') &&
                                            !expanded && (
                                                <p className="text-sm text-error">
                                                    {error(index, 'command')}
                                                </p>
                                            )}

                                        {expanded && (
                                            <div
                                                id={`${id}-more`}
                                                className="space-y-3 rounded-box border border-base-300 bg-base-100 p-4"
                                            >
                                                <Input
                                                    label={t('Command')}
                                                    className="font-mono"
                                                    placeholder={usual}
                                                    value={row.command}
                                                    onChange={(e) =>
                                                        update(index, {
                                                            command:
                                                                e.target.value,
                                                        })
                                                    }
                                                    error={error(
                                                        index,
                                                        'command',
                                                    )}
                                                    spellCheck={false}
                                                    autoComplete="off"
                                                    hint={
                                                        row.command === '' &&
                                                        usual !== ''
                                                            ? t(
                                                                  "Empty runs the usual command shown here. It runs without a shell, in the app's folder.",
                                                              )
                                                            : undefined
                                                    }
                                                />
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
                                                                    e.target
                                                                        .value,
                                                            })
                                                        }
                                                        error={error(
                                                            index,
                                                            'replicas',
                                                        )}
                                                        hint={t(
                                                            'How many copies run at the same time.',
                                                        )}
                                                    />
                                                )}
                                                <Select
                                                    label={t(
                                                        'Replacing a release',
                                                    )}
                                                    value={row.overlap}
                                                    onChange={(e) =>
                                                        update(index, {
                                                            overlap: e.target
                                                                .value as Row['overlap'],
                                                        })
                                                    }
                                                    error={error(
                                                        index,
                                                        'overlap',
                                                    )}
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
                                                        {t(
                                                            'Stop the old one first',
                                                        )}
                                                    </option>
                                                </Select>
                                                <Input
                                                    label={t(
                                                        'Time to finish its work when stopped (seconds)',
                                                    )}
                                                    type="number"
                                                    min={1}
                                                    max={600}
                                                    value={
                                                        row.stop_grace_seconds
                                                    }
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
                                                    label={t(
                                                        'Memory limit (MB)',
                                                    )}
                                                    type="number"
                                                    min={16}
                                                    placeholder={t(
                                                        'Same as the app',
                                                    )}
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
                                        )}
                                    </div>
                                )}
                            </li>
                        );
                    })}

                    <li>
                        <button
                            type="button"
                            onClick={add}
                            className="flex w-full items-center justify-center gap-2 rounded-box border border-dashed border-base-300 p-4 text-sm font-medium text-base-content/70 transition-colors hover:border-primary hover:text-primary motion-reduce:transition-none"
                        >
                            <Plus className="size-4" aria-hidden />
                            {t('Add your own service')}
                        </button>
                    </li>
                </ul>

                <div className="sticky bottom-0 -mx-5 flex flex-wrap items-center gap-3 border-t border-base-300 bg-base-100/95 px-5 py-3 backdrop-blur sm:-mx-6 sm:px-6">
                    <Button type="submit" loading={form.processing}>
                        {t('Save services')}
                    </Button>
                    {dirty && (
                        <span
                            className="flex items-center gap-2 text-sm text-warning"
                            role="status"
                        >
                            <span
                                className="size-2 rounded-full bg-warning"
                                aria-hidden
                            />
                            {t('Unsaved changes')}
                        </span>
                    )}
                </div>
            </form>
        </Card>
    );
}
