import { router, useForm, usePage } from '@inertiajs/react';
import {
    Bell,
    MessageSquare,
    Pencil,
    Plus,
    Send,
    Trash2,
    Webhook,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    AlertEventOption,
    AlertKindOption,
    AlertTargetRow,
} from '@/types';

function KindIcon({ kind, className }: { kind: string; className?: string }) {
    const Icon =
        kind === 'telegram'
            ? Send
            : kind === 'ntfy'
              ? Bell
              : kind === 'json'
                ? Webhook
                : MessageSquare;

    return <Icon className={cn('size-5', className)} aria-hidden />;
}

type FormData = {
    kind: string;
    name: string;
    address: string;
    chat_id: string;
    events: string[];
    enabled: boolean;
};

function TargetModal({
    target,
    kinds,
    events,
    onClose,
}: {
    target: AlertTargetRow | null;
    kinds: AlertKindOption[];
    events: AlertEventOption[];
    onClose: () => void;
}) {
    const { t } = useT();
    const confirmed = useConfirmedAction();
    const defaults = events.filter((e) => e.default_on).map((e) => e.key);
    const form = useForm<FormData>({
        kind: target?.kind ?? kinds[0]?.value ?? '',
        name: target?.name ?? '',
        address: '',
        chat_id: '',
        events: target?.events ?? defaults,
        enabled: target?.enabled ?? true,
    });
    const kind = kinds.find((k) => k.value === form.data.kind) ?? kinds[0];
    const groups = [...new Set(events.map((e) => e.group))];

    const toggleEvent = (key: string, on: boolean) =>
        form.setData(
            'events',
            on
                ? [...form.data.events, key]
                : form.data.events.filter((k) => k !== key),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        void confirmed.run(() => {
            const options = {
                preserveScroll: true,
                onSuccess: onClose,
            };

            if (target) {
                form.put(NotificationController.update(target.id).url, options);
            } else {
                form.post(NotificationController.store().url, options);
            }
        });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={target ? t('Edit target') : t('Add a target')}
            size="lg"
        >
            <form onSubmit={submit} className="space-y-4">
                {!target && (
                    <fieldset className="space-y-2">
                        <legend className="mb-1 label-text">
                            {t('Where should messages go?')}
                        </legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {kinds.map((k) => (
                                <label
                                    key={k.value}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-3 rounded-box border p-3',
                                        form.data.kind === k.value
                                            ? 'border-primary bg-primary/10'
                                            : 'border-base-300',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="kind"
                                        className="radio radio-primary"
                                        checked={form.data.kind === k.value}
                                        onChange={() =>
                                            form.setData('kind', k.value)
                                        }
                                    />
                                    <KindIcon
                                        kind={k.value}
                                        className="shrink-0 text-base-content/70"
                                    />
                                    <span className="min-w-0 text-sm font-medium">
                                        {k.label}
                                    </span>
                                </label>
                            ))}
                        </div>
                        {form.errors.kind && (
                            <p className="text-sm text-error">
                                {form.errors.kind}
                            </p>
                        )}
                    </fieldset>
                )}
                {target && (
                    <p className="flex items-center gap-2 text-sm text-base-content/70">
                        <KindIcon kind={target.kind} className="size-4" />
                        <span className="min-w-0 truncate">
                            {target.kind_label}
                        </span>
                    </p>
                )}
                <Input
                    label={t('Name')}
                    required
                    maxLength={60}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={form.errors.name}
                />
                {kind && (
                    <Input
                        label={kind.address_label}
                        type="password"
                        autoComplete="new-password"
                        spellCheck={false}
                        required={!target}
                        value={form.data.address}
                        onChange={(e) =>
                            form.setData('address', e.target.value)
                        }
                        error={form.errors.address}
                        hint={
                            target
                                ? t(
                                      'Saved for :host. Leave it empty to keep it.',
                                      { host: target.host },
                                  ) +
                                  ' ' +
                                  kind.hint
                                : kind.hint
                        }
                    />
                )}
                {kind?.needs_chat_id && (
                    <Input
                        label={t('Chat id')}
                        required={!target}
                        spellCheck={false}
                        value={form.data.chat_id}
                        onChange={(e) =>
                            form.setData('chat_id', e.target.value)
                        }
                        error={form.errors.chat_id}
                        hint={
                            target?.chat_id
                                ? t(
                                      'Saved as :chat. Leave it empty to keep it.',
                                      { chat: target.chat_id },
                                  )
                                : t(
                                      'The numeric chat id (it may start with a minus sign) or @channelname.',
                                  )
                        }
                    />
                )}
                <fieldset className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <legend className="label-text">
                            {t('Send a message when')}
                        </legend>
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                variant="soft"
                                onClick={() => form.setData('events', defaults)}
                            >
                                {t('Problems only')}
                            </Button>
                            <Button
                                size="sm"
                                variant="soft"
                                onClick={() =>
                                    form.setData(
                                        'events',
                                        events.map((e) => e.key),
                                    )
                                }
                            >
                                {t('Everything')}
                            </Button>
                        </div>
                    </div>
                    {groups.map((group) => (
                        <div key={group} className="space-y-2">
                            <p className="text-xs font-semibold tracking-wide text-base-content/60 uppercase">
                                {group}
                            </p>
                            {events
                                .filter((e) => e.group === group)
                                .map((e) => (
                                    <label
                                        key={e.key}
                                        className="flex cursor-pointer items-start gap-3"
                                    >
                                        <input
                                            type="checkbox"
                                            className="checkbox mt-0.5 checkbox-primary"
                                            checked={form.data.events.includes(
                                                e.key,
                                            )}
                                            onChange={(ev) =>
                                                toggleEvent(
                                                    e.key,
                                                    ev.target.checked,
                                                )
                                            }
                                        />
                                        <span className="min-w-0 text-sm">
                                            {e.label}
                                        </span>
                                    </label>
                                ))}
                        </div>
                    ))}
                    {form.errors.events && (
                        <p className="text-sm text-error">
                            {form.errors.events}
                        </p>
                    )}
                </fieldset>
                <label className="flex cursor-pointer items-center gap-3">
                    <input
                        type="checkbox"
                        role="switch"
                        className="switch switch-primary"
                        checked={form.data.enabled}
                        onChange={(e) =>
                            form.setData('enabled', e.target.checked)
                        }
                    />
                    <span className="text-sm">{t('Send messages here')}</span>
                </label>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        {t('Save')}
                    </Button>
                </div>
            </form>
            {confirmed.modal}
        </Modal>
    );
}

export default function Notifications({
    targets,
    events,
    kinds,
    max_targets,
}: {
    targets: AlertTargetRow[];
    events: AlertEventOption[];
    kinds: AlertKindOption[];
    max_targets: number;
}) {
    const { t, tn } = useT();
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();
    const [editing, setEditing] = useState<AlertTargetRow | 'new' | null>(null);
    const [removing, setRemoving] = useState<AlertTargetRow | null>(null);
    const [testing, setTesting] = useState<number | null>(null);
    const full = targets.length >= max_targets;

    const test = (target: AlertTargetRow) => {
        setTesting(target.id);
        router.post(
            NotificationController.test(target.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setTesting(null),
            },
        );
    };

    const remove = () => {
        const target = removing;
        setRemoving(null);
        if (!target) return;
        void confirmed.run(() =>
            router.delete(NotificationController.destroy(target.id), {
                preserveScroll: true,
            }),
        );
    };

    const status = (target: AlertTargetRow) => {
        if (target.last_error) {
            return (
                <span className="badge-soft badge h-auto max-w-full py-1 break-words whitespace-normal badge-error">
                    {t('Last message failed: :error', {
                        error: target.last_error,
                    })}
                </span>
            );
        }

        if (target.last_ok_at) {
            return (
                <span className="badge-soft badge h-auto py-1 badge-success">
                    {t('Last message delivered :ago', {
                        ago: timeAgo(target.last_ok_at),
                    })}
                </span>
            );
        }

        return (
            <span className="badge-soft badge h-auto py-1">
                {t('No message yet')}
            </span>
        );
    };

    return (
        <AppLayout title={t('Notifications')}>
            <PageHeader
                title={t('Notifications')}
                description={t(
                    'Get told in a chat when something needs your attention.',
                )}
            />
            <div className="grid items-start gap-6 xl:grid-cols-3">
                <div className="min-w-0 xl:col-span-2">
                    <Card
                        title={t('Where messages go')}
                        description={t(
                            'Addresses are stored encrypted and never shown again. Messages never hold a log or a secret.',
                        )}
                        actions={
                            <Button
                                onClick={() => setEditing('new')}
                                disabled={full}
                            >
                                <Plus className="size-4" aria-hidden />
                                {t('Add a target')}
                            </Button>
                        }
                    >
                        {errors.alerts && (
                            <Alert variant="error">{errors.alerts}</Alert>
                        )}
                        {full && (
                            <p className="text-sm text-base-content/70">
                                {t(
                                    'You can have up to :max targets. Remove one to add another.',
                                    { max: max_targets },
                                )}
                            </p>
                        )}
                        {targets.length === 0 ? (
                            <div className="flex flex-col items-center gap-3 rounded-box border-2 border-dashed border-base-300 p-8 text-center">
                                <Bell
                                    className="size-8 text-base-content/60"
                                    aria-hidden
                                />
                                <p className="text-sm text-base-content/70">
                                    {t(
                                        'No messages are sent anywhere yet. Add Slack, Discord, Telegram or another place to hear about problems.',
                                    )}
                                </p>
                                <Button onClick={() => setEditing('new')}>
                                    <Plus className="size-4" aria-hidden />
                                    {t('Add a target')}
                                </Button>
                            </div>
                        ) : (
                            <ul className="space-y-3">
                                {targets.map((target) => (
                                    <li
                                        key={target.id}
                                        className="space-y-3 rounded-box border border-base-300 p-4"
                                    >
                                        <div className="flex items-start gap-3">
                                            <KindIcon
                                                kind={target.kind}
                                                className="mt-0.5 shrink-0 text-base-content/70"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate font-medium">
                                                    {target.name}
                                                </p>
                                                <p className="truncate text-sm text-base-content/60">
                                                    {target.kind_label}
                                                    {' · '}
                                                    {target.host}
                                                </p>
                                                <p className="text-sm text-base-content/60">
                                                    {tn(
                                                        'one::count event|other::count events',
                                                        target.events.length,
                                                    )}
                                                </p>
                                            </div>
                                            <input
                                                type="checkbox"
                                                role="switch"
                                                className="switch switch-primary"
                                                aria-label={t(
                                                    'Send messages here',
                                                )}
                                                checked={target.enabled}
                                                onChange={() =>
                                                    router.put(
                                                        NotificationController.toggle(
                                                            target.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="min-w-0">
                                                {status(target)}
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="soft"
                                                    loading={
                                                        testing === target.id
                                                    }
                                                    onClick={() => test(target)}
                                                >
                                                    <Send
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                    {t('Test')}
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="soft"
                                                    onClick={() =>
                                                        setEditing(target)
                                                    }
                                                >
                                                    <Pencil
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                    {t('Edit')}
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setRemoving(target)
                                                    }
                                                >
                                                    <Trash2
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                    {t('Delete')}
                                                </Button>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
                <div className="min-w-0">
                    <Card title={t('What triggers a message')}>
                        <ul className="list-disc space-y-2 pl-5 text-sm text-base-content/70">
                            <li>
                                {t(
                                    'A message is sent when something changes: an app is called down after three failed checks in a row, and one message follows when it recovers.',
                                )}
                            </li>
                            <li>
                                {t(
                                    'A certificate that failed to renew, or is about to expire.',
                                )}
                            </li>
                            <li>
                                {t('The disk filling up, at 85% and at 95%.')}
                            </li>
                            <li>{t('A backup that failed.')}</li>
                            <li>
                                {t(
                                    'A database server that stopped, an automatic rollback, and the result of a deployment.',
                                )}
                            </li>
                        </ul>
                    </Card>
                </div>
            </div>
            {editing !== null && (
                <TargetModal
                    key={editing === 'new' ? 'new' : editing.id}
                    target={editing === 'new' ? null : editing}
                    kinds={kinds}
                    events={events}
                    onClose={() => setEditing(null)}
                />
            )}
            <ConfirmModal
                open={removing !== null}
                title={t('Delete this target?')}
                confirmLabel={t('Delete')}
                onConfirm={remove}
                onClose={() => setRemoving(null)}
            >
                {t('Nothing is sent to :name any more.', {
                    name: removing?.name ?? '',
                })}
            </ConfirmModal>
            {confirmed.modal}
        </AppLayout>
    );
}
