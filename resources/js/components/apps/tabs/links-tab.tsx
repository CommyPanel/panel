import { Link as InertiaLink, router, useForm } from '@inertiajs/react';
import { Database, Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import AppLinkController from '@/actions/App/Http/Controllers/AppLinkController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { Select } from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import type { Params } from '@/lib/i18n';
import { Trans } from '@/lib/trans';
import type { AppSettings, LinksOverview } from '@/types';

type Removal =
    | { kind: 'database'; id: number; label: string }
    | { kind: 'app'; id: number; label: string };

async function getLinks(
    url: string,
    t: (key: string, params?: Params) => string,
): Promise<LinksOverview> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const body = (await response.json().catch(() => ({}))) as LinksOverview;

    if (!response.ok)
        throw new Error(
            t('The request failed (:status).', { status: response.status }),
        );

    return body;
}

const prefixFor = (slug: string) => slug.toUpperCase().replace(/-/g, '_');

function AddDatabaseLink({
    slug,
    options,
    open,
    onClose,
    onDone,
}: {
    slug: string;
    options: LinksOverview['available_databases'];
    open: boolean;
    onClose: () => void;
    onDone: () => void;
}) {
    const { t } = useT();
    const form = useForm({ database_id: '', env_prefix: 'DATABASE' });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppLinkController.storeDatabase(slug), {
            preserveScroll: true,
            onSuccess: () => {
                close();
                onDone();
            },
        });
    };

    return (
        <Modal open={open} onClose={close} title={t('Link a database')}>
            <form onSubmit={submit} className="space-y-4">
                <Select
                    label={t('Database')}
                    required
                    value={form.data.database_id}
                    onChange={(e) =>
                        form.setData('database_id', e.target.value)
                    }
                    error={form.errors.database_id}
                >
                    <option value="">{t('Choose a database')}</option>
                    {options.map((o) => (
                        <option key={o.id} value={o.id}>
                            {o.label}
                        </option>
                    ))}
                </Select>
                <Input
                    label={t('Names of the environment variables')}
                    required
                    autoComplete="off"
                    spellCheck={false}
                    value={form.data.env_prefix}
                    onChange={(e) =>
                        form.setData('env_prefix', e.target.value.toUpperCase())
                    }
                    error={
                        form.errors.env_prefix ??
                        (form.errors as Record<string, string | undefined>).link
                    }
                    hint={t(
                        'The app gets :url, :host, _PORT, _NAME, _USER and _PASSWORD.',
                        {
                            url: `${form.data.env_prefix || 'DATABASE'}_URL`,
                            host: `${form.data.env_prefix || 'DATABASE'}_HOST`,
                        },
                    )}
                />
                <ul className="list-disc space-y-1.5 pl-5 text-sm text-base-content/80">
                    <li>
                        {t(
                            'The app can then reach that database, and only that one, over a private network made for this link.',
                        )}
                    </li>
                    <li>
                        <Trans
                            text="It applies from the app's <b>next deployment</b>. Deploy the app again after linking."
                            components={{ b: <strong /> }}
                        />
                    </li>
                </ul>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        {t('Link database')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

function AddAppLink({
    slug,
    options,
    open,
    onClose,
    onDone,
}: {
    slug: string;
    options: LinksOverview['available_apps'];
    open: boolean;
    onClose: () => void;
    onDone: () => void;
}) {
    const { t } = useT();
    const form = useForm({ target: '', env_prefix: '' });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppLinkController.storeApp(slug), {
            preserveScroll: true,
            onSuccess: () => {
                close();
                onDone();
            },
        });
    };

    return (
        <Modal open={open} onClose={close} title={t('Link another app')}>
            <form onSubmit={submit} className="space-y-4">
                <Select
                    label={t('App to reach')}
                    required
                    value={form.data.target}
                    onChange={(e) =>
                        form.setData({
                            target: e.target.value,
                            env_prefix: e.target.value
                                ? prefixFor(e.target.value)
                                : '',
                        })
                    }
                    error={form.errors.target}
                >
                    <option value="">{t('Choose an app')}</option>
                    {options.map((o) => (
                        <option key={o.slug} value={o.slug}>
                            {o.name}
                        </option>
                    ))}
                </Select>
                <Input
                    label={t('Names of the environment variables')}
                    required
                    autoComplete="off"
                    spellCheck={false}
                    value={form.data.env_prefix}
                    onChange={(e) =>
                        form.setData('env_prefix', e.target.value.toUpperCase())
                    }
                    error={
                        form.errors.env_prefix ??
                        (form.errors as Record<string, string | undefined>).link
                    }
                    hint={t('The app gets :url, :host and :port.', {
                        url: `${form.data.env_prefix || 'API'}_URL`,
                        host: `${form.data.env_prefix || 'API'}_HOST`,
                        port: `${form.data.env_prefix || 'API'}_PORT`,
                    })}
                />
                <ul className="list-disc space-y-1.5 pl-5 text-sm text-base-content/80">
                    <li>
                        <Trans
                            text="This app can reach the other app at <code>http://&lt;its name&gt;:&lt;its port&gt;</code>, inside the server, without going through the internet."
                            components={{ code: <code /> }}
                        />
                    </li>
                    <li>
                        {t(
                            'Only these two apps share the private network. No other app can use it.',
                        )}
                    </li>
                    <li>
                        <Trans
                            text="Both apps need a <b>new deployment</b>: this one to get the address, the other to answer to its name."
                            components={{ b: <strong /> }}
                        />
                    </li>
                </ul>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        {t('Link app')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

/**
 * What this app is allowed to reach: databases and other apps. Apps are
 * isolated from each other by default; a link opens one private network between
 * exactly two ends. Only mounted while the tab is open.
 */
export function LinksTab({ app }: { app: AppSettings }) {
    const { t } = useT();
    const [data, setData] = useState<LinksOverview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [addingDatabase, setAddingDatabase] = useState(false);
    const [addingApp, setAddingApp] = useState(false);
    const [removing, setRemoving] = useState<Removal | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            setData(await getLinks(AppLinkController.index(app.slug).url, t));
            setError(null);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : t('The links could not be loaded.'),
            );
        }
    }, [app.slug, t]);

    useEffect(() => {
        void load();
    }, [load]);

    const remove = () => {
        if (!removing) return;

        const url =
            removing.kind === 'database'
                ? AppLinkController.destroyDatabase({
                      app: app.slug,
                      databaseLink: removing.id,
                  })
                : AppLinkController.destroyApp({
                      app: app.slug,
                      appLink: removing.id,
                  });

        router.delete(url, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setRemoving(null);
                void load();
            },
        });
    };

    if (error) return <Alert variant="error">{error}</Alert>;

    if (data === null) {
        return (
            <p className="text-sm text-base-content/70" role="status">
                {t('Loading...')}
            </p>
        );
    }

    const empty = data.databases.length === 0 && data.apps.length === 0;

    return (
        <div className="space-y-6">
            <Card
                title={t('Links')}
                description={t(
                    'Apps are separate from each other by default. A link lets this app reach one database, or one other app, over a private network made for that link alone.',
                )}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            onClick={() => setAddingDatabase(true)}
                            disabled={data.available_databases.length === 0}
                        >
                            <Database className="size-4" aria-hidden />
                            {t('Link a database')}
                        </Button>
                        <Button
                            size="sm"
                            variant="soft"
                            onClick={() => setAddingApp(true)}
                            disabled={data.available_apps.length === 0}
                        >
                            <Plus className="size-4" aria-hidden />
                            {t('Link an app')}
                        </Button>
                    </div>
                }
            >
                {empty && (
                    <div className="space-y-2 text-sm text-base-content/70">
                        <p>{t('This app is not linked to anything.')}</p>
                        {data.available_databases.length === 0 && (
                            <p>
                                <Trans
                                    text="To give it a database, first <link>add a database server and a database</link>."
                                    components={{
                                        link: (
                                            <InertiaLink
                                                href="/databases"
                                                className="link link-primary"
                                            />
                                        ),
                                    }}
                                />
                            </p>
                        )}
                    </div>
                )}

                {data.databases.length > 0 && (
                    <section className="space-y-2">
                        <h3 className="text-sm font-medium">
                            {t('Databases')}
                        </h3>
                        <ul className="divide-y divide-base-300 rounded-field border border-base-300">
                            {data.databases.map((link) => (
                                <li
                                    key={link.id}
                                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                >
                                    <span className="min-w-0 text-sm">
                                        <Trans
                                            text="<code>:database</code> on <link>:server</link> (:engine)"
                                            components={{
                                                code: <code />,
                                                link: (
                                                    <InertiaLink
                                                        href={`/databases/${link.server_slug}`}
                                                        className="link link-primary"
                                                    />
                                                ),
                                            }}
                                            values={{
                                                database: link.database,
                                                server: link.server,
                                                engine: link.engine,
                                            }}
                                        />
                                        <span className="block text-xs text-base-content/70">
                                            <Trans
                                                text="Variables: <code>:url</code>, <code>:host</code>, ..."
                                                components={{ code: <code /> }}
                                                values={{
                                                    url: `${link.prefix}_URL`,
                                                    host: `${link.prefix}_HOST`,
                                                }}
                                            />
                                            {!link.running &&
                                                ` · ${t('The server is not running.')}`}
                                        </span>
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setRemoving({
                                                kind: 'database',
                                                id: link.id,
                                                label: link.database,
                                            })
                                        }
                                    >
                                        <Trash2
                                            className="size-4"
                                            aria-hidden
                                        />
                                        {t('Remove')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {data.apps.length > 0 && (
                    <section className="space-y-2">
                        <h3 className="text-sm font-medium">
                            {t('Other apps this app can reach')}
                        </h3>
                        <ul className="divide-y divide-base-300 rounded-field border border-base-300">
                            {data.apps.map((link) => (
                                <li
                                    key={link.id}
                                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                >
                                    <span className="min-w-0 text-sm">
                                        {link.app}
                                        <span className="block text-xs text-base-content/70">
                                            <Trans
                                                text="Variables: <code>:url</code>, <code>:host</code>, <code>:port</code>"
                                                components={{ code: <code /> }}
                                                values={{
                                                    url: `${link.prefix}_URL`,
                                                    host: `${link.prefix}_HOST`,
                                                    port: `${link.prefix}_PORT`,
                                                }}
                                            />
                                        </span>
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setRemoving({
                                                kind: 'app',
                                                id: link.id,
                                                label: link.app,
                                            })
                                        }
                                    >
                                        <Trash2
                                            className="size-4"
                                            aria-hidden
                                        />
                                        {t('Remove')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {data.used_by.length > 0 && (
                    <p className="text-sm text-base-content/70">
                        {t('These apps can reach this one: :apps.', {
                            apps: data.used_by.map((u) => u.app).join(', '),
                        })}
                    </p>
                )}
            </Card>

            <Card title={t('How links work')}>
                <ul className="list-disc space-y-1.5 pl-5 text-sm text-base-content/80">
                    <li>
                        <Trans
                            text="<b>A link applies from the next deployment.</b> The connection is added to the app's environment variables when its new container starts, so deploy the app after adding or removing a link."
                            components={{ b: <strong /> }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>A password change reaches an app the same way.</b> After changing a database's password, deploy the apps linked to it."
                            components={{ b: <strong /> }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>Nothing is shared by default.</b> Without a link an app cannot reach another app or any database, and a database server has no address on the internet."
                            components={{ b: <strong /> }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>You can also use a public address.</b> Two apps with domains can always call each other over HTTPS. A link is for traffic that should stay inside the server."
                            components={{ b: <strong /> }}
                        />
                    </li>
                    <li>
                        {t(
                            'Variables you add yourself with the same name win over the ones a link adds.',
                        )}
                    </li>
                </ul>
            </Card>

            <AddDatabaseLink
                slug={app.slug}
                options={data.available_databases}
                open={addingDatabase}
                onClose={() => setAddingDatabase(false)}
                onDone={() => void load()}
            />
            <AddAppLink
                slug={app.slug}
                options={data.available_apps}
                open={addingApp}
                onClose={() => setAddingApp(false)}
                onDone={() => void load()}
            />
            <ConfirmModal
                open={removing !== null}
                title={t('Remove this link?')}
                confirmLabel={t('Remove link')}
                loading={busy}
                onConfirm={remove}
                onClose={() => setRemoving(null)}
            >
                <Trans
                    text=":app will no longer reach <b>:target</b> from its next deployment. Until then it keeps working as it is."
                    components={{ b: <strong /> }}
                    values={{ app: app.name, target: removing?.label ?? '' }}
                />
            </ConfirmModal>
        </div>
    );
}
