import { Database, Server, ServerCog, SkipForward } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import SetupDatabaseController from '@/actions/App/Http/Controllers/SetupDatabaseController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import { jsonHeaders } from '@/lib/post-json';
import { cn } from '@/lib/utils';

type Mode = 'existing' | 'new' | 'dedicated' | 'none';

type StepData = {
    choice: {
        mode: Mode | null;
        status: 'pending' | 'ready' | 'failed' | null;
        error: string | null;
        server: string | null;
        server_status: string | null;
        database: string | null;
        linked: { server: string; database: string; engine: string } | null;
    };
    servers: {
        slug: string;
        name: string;
        engine: string;
        engine_label: string;
        version: string;
        running: boolean;
    }[];
    databases: {
        id: number;
        name: string;
        server: string;
        server_name: string;
        engine_label: string;
        version: string;
    }[];
    engines: { value: string; label: string; versions: string[] }[];
    suggested: { database: string; server: string };
    can: { link: boolean; manage: boolean };
};

type Props = {
    slug: string;
    /**
     * Called whenever the step's state changes: `done` is true when the choice is carried out (a database
     * is linked, or "none" was chosen), `pending` while a dedicated server is still starting. The wizard
     * should not move on until `done`.
     */
    onChange?: (state: { done: boolean; pending: boolean }) => void;
};

const POLL_MS = 3000;

function ChoiceCard({
    mode,
    current,
    onSelect,
    icon,
    title,
    children,
    disabled,
}: {
    mode: Mode;
    current: Mode | '';
    onSelect: (mode: Mode) => void;
    icon: ReactNode;
    title: string;
    children: ReactNode;
    disabled?: boolean;
}) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-start gap-3 rounded-box border border-base-300 p-4',
                current === mode && 'border-primary bg-primary/5',
                disabled && 'cursor-not-allowed opacity-60',
            )}
        >
            <input
                type="radio"
                name="database-mode"
                className="radio mt-1 radio-primary"
                checked={current === mode}
                disabled={disabled}
                onChange={() => onSelect(mode)}
            />
            <span className="min-w-0 space-y-1">
                <span className="flex items-center gap-2 font-medium">
                    {icon}
                    {title}
                </span>
                <span className="block text-sm text-base-content/70">
                    {children}
                </span>
            </span>
        </label>
    );
}

/**
 * The database step of the Laravel wizard: use an existing database, make a new one on an existing
 * server, make a server just for this app (its user may create and drop databases), or none.
 */
export function DatabaseStep({ slug, onChange }: Props) {
    const { t } = useT();
    const [data, setData] = useState<StepData | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [mode, setMode] = useState<Mode | ''>('');
    const [databaseId, setDatabaseId] = useState('');
    const [serverSlug, setServerSlug] = useState('');
    const [name, setName] = useState('');
    const [serverName, setServerName] = useState('');
    const [engine, setEngine] = useState('');
    const [version, setVersion] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const filled = useRef(false);

    const load = useCallback(async () => {
        try {
            const response = await fetch(
                SetupDatabaseController.show(slug).url,
                { credentials: 'same-origin', headers: jsonHeaders() },
            );

            if (!response.ok) throw new Error('failed');

            const body = (await response.json()) as StepData;
            setData(body);
            setLoadError(null);

            // The first time: start from what the app already has, else from the suggestions.
            if (!filled.current) {
                filled.current = true;
                setMode(body.choice.mode ?? '');
                setName(body.choice.database ?? body.suggested.database);
                setServerName(body.suggested.server);
                setEngine(body.engines[0]?.value ?? '');
                setVersion(body.engines[0]?.versions[0] ?? '');
                setServerSlug(
                    body.choice.server ?? body.servers[0]?.slug ?? '',
                );
            }
        } catch {
            setLoadError(t('The database choices could not be loaded.'));
        }
    }, [slug, t]);

    useEffect(() => {
        void load();
    }, [load]);

    const pending = data?.choice.status === 'pending';
    const done =
        data !== null &&
        data.choice.status === 'ready' &&
        (data.choice.mode === 'none' || data.choice.linked !== null);

    // A dedicated server takes a while to start: ask again until it runs and its first database is made.
    useEffect(() => {
        if (!pending) return;

        const timer = setInterval(() => void load(), POLL_MS);

        return () => clearInterval(timer);
    }, [pending, load]);

    useEffect(() => {
        onChange?.({ done, pending });
    }, [done, pending]); // eslint-disable-line react-hooks/exhaustive-deps

    const save = async () => {
        setSaving(true);
        setErrors({});

        const body: Record<string, unknown> = { mode };

        if (mode === 'existing') body.database_id = Number(databaseId);
        if (mode === 'new') {
            body.server = serverSlug;
            body.name = name;
        }
        if (mode === 'dedicated') {
            body.engine = engine;
            body.version = version;
            body.name = name;
            body.server_name = serverName;
        }

        try {
            const response = await fetch(
                SetupDatabaseController.update(slug).url,
                {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: jsonHeaders(),
                    body: JSON.stringify(body),
                },
            );

            if (response.status === 422) {
                const answer = (await response.json()) as {
                    errors?: Record<string, string[]>;
                };
                setErrors(
                    Object.fromEntries(
                        Object.entries(answer.errors ?? {}).map(([k, v]) => [
                            k,
                            v[0] ?? '',
                        ]),
                    ),
                );
            } else if (response.status === 403) {
                setErrors({
                    mode: t('You are not allowed to choose this option.'),
                });
            } else if (!response.ok) {
                setErrors({
                    mode: t('The request failed (:status).', {
                        status: response.status,
                    }),
                });
            } else {
                await load();
            }
        } catch {
            setErrors({ mode: t('The request failed.') });
        } finally {
            setSaving(false);
        }
    };

    if (loadError) return <Alert variant="error">{loadError}</Alert>;

    if (data === null) {
        return (
            <p className="text-sm text-base-content/70" role="status">
                {t('Loading...')}
            </p>
        );
    }

    const canLink = data.can.link;
    const canManage = data.can.manage;
    const versions =
        data.engines.find((e) => e.value === engine)?.versions ?? [];
    const ready =
        mode !== '' &&
        ((mode === 'existing' && databaseId !== '') ||
            (mode === 'new' && serverSlug !== '' && name !== '') ||
            (mode === 'dedicated' &&
                engine !== '' &&
                version !== '' &&
                name !== '') ||
            mode === 'none');

    return (
        <div className="space-y-4">
            {data.choice.linked && data.choice.status === 'ready' && (
                <Alert variant="success">
                    {t(':database on :server (:engine) is linked.', {
                        database: data.choice.linked.database,
                        server: data.choice.linked.server,
                        engine: data.choice.linked.engine,
                    })}
                </Alert>
            )}
            {data.choice.mode === 'none' && data.choice.status === 'ready' && (
                <Alert variant="success">{t('No database is linked.')}</Alert>
            )}
            {pending && (
                <Alert variant="info">
                    <span role="status">
                        {t(
                            'The database server is starting (:status). The first start downloads the image and can take a few minutes. This updates by itself, and the app can be finished once it runs.',
                            { status: data.choice.server_status ?? '...' },
                        )}
                    </span>
                </Alert>
            )}
            {data.choice.status === 'failed' && (
                <Alert variant="error">
                    {data.choice.error ??
                        t('The database could not be set up.')}
                </Alert>
            )}
            {errors.mode && <Alert variant="error">{errors.mode}</Alert>}

            <fieldset className="space-y-3" disabled={saving}>
                <legend className="sr-only">
                    {t('How the app gets its database')}
                </legend>

                <ChoiceCard
                    mode="existing"
                    current={mode}
                    onSelect={setMode}
                    icon={<Database className="size-4" aria-hidden />}
                    title={t('Use a database that already exists')}
                    disabled={!canLink}
                >
                    {t(
                        'Pick a database on one of your database servers. The app gets its connection details, and only that database.',
                    )}
                </ChoiceCard>
                {mode === 'existing' && (
                    <div className="pl-9">
                        <Select
                            label={t('Database')}
                            value={databaseId}
                            onChange={(e) => setDatabaseId(e.target.value)}
                            error={errors.database_id}
                        >
                            <option value="">
                                {data.databases.length === 0
                                    ? t('None yet')
                                    : t('Choose a database')}
                            </option>
                            {data.databases.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {t(':name on :server (:engine :version)', {
                                        name: d.name,
                                        server: d.server_name,
                                        engine: d.engine_label,
                                        version: d.version,
                                    })}
                                </option>
                            ))}
                        </Select>
                    </div>
                )}

                <ChoiceCard
                    mode="new"
                    current={mode}
                    onSelect={setMode}
                    icon={<Server className="size-4" aria-hidden />}
                    title={t('Make a new database on an existing server')}
                    disabled={!canLink || !canManage}
                >
                    {t(
                        'A new database with its own user is created for this app and linked.',
                    )}
                </ChoiceCard>
                {mode === 'new' && (
                    <div className="space-y-3 pl-9">
                        <Select
                            label={t('Database server')}
                            value={serverSlug}
                            onChange={(e) => setServerSlug(e.target.value)}
                            error={errors.server}
                        >
                            <option value="">
                                {data.servers.length === 0
                                    ? t('None yet')
                                    : t('Choose a server')}
                            </option>
                            {data.servers.map((s) => (
                                <option
                                    key={s.slug}
                                    value={s.slug}
                                    disabled={!s.running}
                                >
                                    {t(':name (:engine :version)', {
                                        name: s.name,
                                        engine: s.engine_label,
                                        version: s.version,
                                    })}
                                </option>
                            ))}
                        </Select>
                        <Input
                            label={t('Name of the new database')}
                            spellCheck={false}
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            error={errors.name}
                            hint={t(
                                'Lowercase letters, digits and underscores, starting with a letter.',
                            )}
                        />
                    </div>
                )}

                <ChoiceCard
                    mode="dedicated"
                    current={mode}
                    onSelect={setMode}
                    icon={<ServerCog className="size-4" aria-hidden />}
                    title={t('Make a database server just for this app')}
                    disabled={!canLink || !canManage}
                >
                    {t(
                        'A new server that only this app uses, isolated from your other apps. The app can create and drop databases on it by itself, which is what multi-database and tenancy packages need. Deleting the app keeps the server and its data.',
                    )}
                </ChoiceCard>
                {mode === 'dedicated' && (
                    <div className="space-y-3 pl-9">
                        <Input
                            label={t('Name of the server')}
                            value={serverName}
                            onChange={(e) => setServerName(e.target.value)}
                            error={errors.server_name}
                        />
                        <Select
                            label={t('Engine')}
                            value={engine}
                            onChange={(e) => {
                                setEngine(e.target.value);
                                setVersion(
                                    data.engines.find(
                                        (x) => x.value === e.target.value,
                                    )?.versions[0] ?? '',
                                );
                            }}
                            error={errors.engine}
                        >
                            {data.engines.map((e) => (
                                <option key={e.value} value={e.value}>
                                    {e.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            label={t('Version')}
                            value={version}
                            onChange={(e) => setVersion(e.target.value)}
                            error={errors.version}
                        >
                            {versions.map((v) => (
                                <option key={v} value={v}>
                                    {v}
                                </option>
                            ))}
                        </Select>
                        <Input
                            label={t('Name of the first database')}
                            spellCheck={false}
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            error={errors.name}
                            hint={t(
                                'The app can make more databases itself. Those are not listed under Databases.',
                            )}
                        />
                    </div>
                )}

                <ChoiceCard
                    mode="none"
                    current={mode}
                    onSelect={setMode}
                    icon={<SkipForward className="size-4" aria-hidden />}
                    title={t('No database')}
                >
                    {t(
                        'For an app that uses SQLite or no database. Nothing is linked. You can link one later on the Links tab.',
                    )}
                </ChoiceCard>
            </fieldset>

            {!canLink && (
                <p className="text-sm text-base-content/70">
                    {t(
                        'You do not have permission to link apps to databases, so only "No database" is available.',
                    )}
                </p>
            )}

            <Button
                onClick={() => void save()}
                loading={saving}
                disabled={!ready || pending}
            >
                {t('Use this choice')}
            </Button>
        </div>
    );
}
