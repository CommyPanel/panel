import { router } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import ManagedDatabaseController from '@/actions/App/Http/Controllers/ManagedDatabaseController';
import { Button } from '@/components/ui/button';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';
import type { ServerPermissions } from '@/types';

type Row = ServerPermissions['users'][number];

/** The words for each privilege; every sentence is a literal so it can be translated. */
export function usePrivilegeLabels(): Record<string, string> {
    const { t } = useT();

    return {
        select: t('Read'),
        insert: t('Add rows'),
        update: t('Change rows'),
        delete: t('Delete rows'),
        create: t('Create'),
        drop: t('Drop'),
        alter: t('Alter'),
        index: t('Indexes'),
        references: t('References'),
        create_temporary_tables: t('Temporary tables'),
        lock_tables: t('Lock tables'),
        create_view: t('Create views'),
        show_view: t('See views'),
        create_routine: t('Create routines'),
        alter_routine: t('Alter routines'),
        execute: t('Run routines'),
        trigger: t('Triggers'),
        event: t('Events'),
        create_user: t('Create users'),
        show_databases: t('List all databases'),
        grant_option: t('Grant rights'),
        login: t('Log in'),
        database_owner: t('Owns its database'),
        create_databases: t('Create databases'),
        create_roles: t('Create roles'),
    };
}

/** The privileges that let a user change data. */
const WRITE_KEYS = ['insert', 'update', 'delete'];

/**
 * The editor of one user's permissions: tick what it may do, or start from a ready-made set, then save. It is
 * a panel under the table, not a dialog, so the matrix above stays in view. Saving asks for the password.
 */
export function PermissionEditor({
    serverSlug,
    row,
    presets,
    onClose,
}: {
    serverSlug: string;
    row: Row;
    presets: Record<string, string[]>;
    onClose: () => void;
}) {
    const { t } = useT();
    const labels = usePrivilegeLabels();
    const confirmed = useConfirmedAction();
    const editable = row.editable_keys;
    const [selected, setSelected] = useState<Set<string>>(
        () => new Set(editable.filter((key) => row.granted[key])),
    );
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const groups: { id: string; legend: string; keys: string[] }[] = [
        {
            id: 'data',
            legend: t('Data'),
            keys: ['select', 'insert', 'update', 'delete'],
        },
        {
            id: 'structure',
            legend: t('Structure'),
            keys: [
                'create',
                'drop',
                'alter',
                'index',
                'references',
                'create_temporary_tables',
                'lock_tables',
            ],
        },
        {
            id: 'code',
            legend: t('Views and code'),
            keys: [
                'create_view',
                'show_view',
                'create_routine',
                'alter_routine',
                'execute',
                'trigger',
                'event',
            ],
        },
        {
            id: 'server',
            legend: t('Server'),
            keys: [
                'create_user',
                'show_databases',
                'grant_option',
                'create_databases',
                'create_roles',
            ],
        },
    ]
        .map((group) => ({
            ...group,
            keys: group.keys.filter((key) => editable.includes(key)),
        }))
        .filter((group) => group.keys.length > 0);

    const presetNames: Record<string, string> = {
        full: t('Full access'),
        read_write: t('Read and write data'),
        read_only: t('Read only'),
    };

    const toggle = (key: string) =>
        setSelected((current) => {
            const next = new Set(current);

            if (next.has(key)) next.delete(key);
            else next.add(key);

            return next;
        });

    // A ready-made set changes the database privileges only; the server ones stay as they are.
    const databaseKeys = presets.full ?? [];
    const apply = (keys: string[]) =>
        setSelected((current) => {
            const next = new Set(
                [...current].filter((key) => !databaseKeys.includes(key)),
            );
            keys.forEach((key) => next.add(key));

            return next;
        });

    const wholeServer = row.scope === 'server';
    const readOnly =
        editable.includes('insert') && !WRITE_KEYS.some((k) => selected.has(k));

    const save = () =>
        void confirmed.run(() =>
            router.put(
                ManagedDatabaseController.privileges({
                    server: serverSlug,
                    database: row.id,
                }),
                { privileges: [...selected] },
                {
                    preserveScroll: true,
                    onStart: () => {
                        setSaving(true);
                        setError(null);
                    },
                    onFinish: () => setSaving(false),
                    onSuccess: onClose,
                    onError: (errors) =>
                        setError(
                            errors.privileges ??
                                t('The permissions could not be saved.'),
                        ),
                },
            ),
        );

    return (
        <section
            aria-label={t('Permissions of :user', { user: row.user })}
            className={cn(
                'space-y-4 rounded-field border p-4',
                wholeServer
                    ? 'border-warning bg-warning/10'
                    : 'border-base-300 bg-base-200/40',
            )}
        >
            {confirmed.modal}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-semibold">
                    {t('Permissions of :user', { user: row.user })}
                </h3>
                <span className="text-sm text-base-content/70">
                    {t(':count of :total', {
                        count: String(selected.size),
                        total: String(editable.length),
                    })}
                </span>
            </div>

            {wholeServer && (
                <p
                    role="note"
                    className="flex items-start gap-2 text-sm text-warning-content"
                >
                    <ShieldAlert
                        className="mt-0.5 size-4 shrink-0 text-warning"
                        aria-hidden
                    />
                    <span className="text-base-content">
                        {t(
                            'These rights apply to the whole server, not to one database. Give only what the app needs.',
                        )}
                    </span>
                </p>
            )}

            {Object.keys(presets).length > 0 && (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm text-base-content/70">
                        {t('Start from')}
                    </span>
                    {Object.entries(presets).map(([name, keys]) => (
                        <Button
                            key={name}
                            variant="soft"
                            size="sm"
                            onClick={() => apply(keys)}
                        >
                            {presetNames[name] ?? name}
                        </Button>
                    ))}
                </div>
            )}

            <div className="grid gap-4 md:grid-cols-2">
                {groups.map((group) => (
                    <fieldset key={group.id} className="min-w-0 space-y-2">
                        <legend className="mb-1 text-sm font-medium">
                            {group.legend}
                        </legend>
                        {group.keys.map((key) => (
                            <label
                                key={key}
                                className="flex cursor-pointer items-center gap-3 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    className="checkbox checkbox-sm checkbox-primary"
                                    checked={selected.has(key)}
                                    onChange={() => toggle(key)}
                                />
                                {labels[key] ?? key}
                            </label>
                        ))}
                    </fieldset>
                ))}
            </div>

            {readOnly && (
                <p role="note" className="text-sm text-warning">
                    {t(
                        'This user cannot change any data any more. Apps that write to this database will fail.',
                    )}
                </p>
            )}
            {error && (
                <p role="alert" className="text-sm text-error">
                    {error}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                <Button onClick={save} loading={saving}>
                    {t('Save permissions')}
                </Button>
                <Button variant="ghost" onClick={onClose} disabled={saving}>
                    {t('Cancel')}
                </Button>
            </div>
        </section>
    );
}
