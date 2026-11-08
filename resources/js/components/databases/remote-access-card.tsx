import { router } from '@inertiajs/react';
import {
    CircleHelp,
    Clock,
    Download,
    Globe,
    LoaderCircle,
    Lock,
    LockOpen,
    RotateCw,
    ShieldAlert,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import DatabaseRemoteController from '@/actions/App/Http/Controllers/DatabaseRemoteController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CommandBox } from '@/components/ui/command-box';
import { Modal } from '@/components/ui/modal';
import { Select } from '@/components/ui/select';
import { Tooltip } from '@/components/ui/tooltip';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import type { Params } from '@/lib/i18n';
import { formatDate } from '@/lib/format';
import { Trans } from '@/lib/trans';
import type { DatabaseServer, RemoteAccess } from '@/types';

const NEVER = 'never';

function durationLabel(
    minutes: number,
    t: (key: string, params?: Params) => string,
    tn: (key: string, count: number, params?: Params) => string,
): string {
    if (minutes < 60) return t(':minutes minutes', { minutes });
    if (minutes < 1440)
        return tn('one::count hour|other::count hours', minutes / 60);

    return tn('one::count day|other::count days', minutes / 1440);
}

/** "12 min left", "3 h 5 min left" and so on, kept fresh while the page is open. */
function useTimeLeft(iso: string | null): string | null {
    const { t, tn } = useT();
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (iso === null) return;

        const timer = setInterval(() => setNow(Date.now()), 15_000);

        return () => clearInterval(timer);
    }, [iso]);

    if (iso === null) return null;

    const seconds = Math.max(
        0,
        Math.round((new Date(iso).getTime() - now) / 1000),
    );

    if (seconds < 60) return t('less than a minute left');
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return t(':minutes min left', { minutes });
    const hours = Math.floor(minutes / 60);
    if (hours < 48)
        return t(':hours h :minutes min left', {
            hours,
            minutes: minutes % 60,
        });

    return tn(
        'one::count day left|other::count days left',
        Math.floor(hours / 24),
    );
}

function connectionCommand(
    server: DatabaseServer,
    remote: RemoteAccess,
): string {
    const host = remote.host;
    const port = remote.port;

    if (server.engine === 'postgres') {
        return `psql "host=${host} port=${port} dbname=DATABASE user=USER sslmode=verify-full sslrootcert=ca.pem"`;
    }

    if (server.engine === 'mariadb') {
        return `mariadb -h ${host} -P ${port} -u USER -p --ssl --ssl-verify-server-cert --ssl-ca=ca.pem`;
    }

    return `mysql -h ${host} -P ${port} -u USER -p --ssl-mode=VERIFY_IDENTITY --ssl-ca=ca.pem`;
}

function OpenModal({
    server,
    remote,
    durations,
    ports,
    myIp,
    changing,
    onClose,
}: {
    server: DatabaseServer;
    remote: RemoteAccess;
    durations: number[];
    ports: [number, number];
    myIp: string | null;
    changing: boolean;
    onClose: () => void;
}) {
    const { t, tn } = useT();
    const confirmed = useConfirmedAction();
    const [allow, setAllow] = useState(changing ? remote.allow.join('\n') : '');
    const [duration, setDuration] = useState<string>(
        changing && remote.forever ? NEVER : '60',
    );
    const [acknowledge, setAcknowledge] = useState(false);
    const [acknowledgeRestart, setAcknowledgeRestart] = useState(false);
    // The first opening restarts the server once, to switch TLS on.
    const needsRestart = !changing && !remote.tls;
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const never = duration === NEVER;

    const addMine = () => {
        if (myIp === null || allow.split(/[\s,;]+/).includes(myIp)) return;
        setAllow(
            (text) => `${text.trim()}${text.trim() === '' ? '' : '\n'}${myIp}`,
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const data = {
            allow,
            ...(needsRestart
                ? { acknowledge_restart: acknowledgeRestart }
                : {}),
            ...(never
                ? { never: true, acknowledge }
                : { duration: Number(duration) }),
        };
        const action = changing
            ? DatabaseRemoteController.update(server.slug)
            : DatabaseRemoteController.store(server.slug);

        void confirmed.run(() =>
            router.visit(action.url, {
                method: action.method,
                data,
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (e) => setErrors(e),
                onSuccess: onClose,
            }),
        );
    };

    return (
        <>
            <Modal
                open
                onClose={onClose}
                title={
                    changing
                        ? t('Change who may connect')
                        : t('Open this server to the internet')
                }
                size="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    {!changing && (
                        <Alert variant="warning">
                            <span className="flex items-start gap-2 text-sm">
                                <TriangleAlert
                                    className="size-5 shrink-0"
                                    aria-hidden
                                />
                                <span>
                                    {t(
                                        'This makes the database server reachable from outside. Only the addresses you list can connect, and only with TLS, but anyone holding a database password and coming from one of them can log in. Prefer a short time, and a VPN or an SSH tunnel for anything permanent.',
                                    )}
                                </span>
                            </span>
                        </Alert>
                    )}

                    <div className="w-full">
                        <label
                            className="mb-1 label-text block px-0!"
                            htmlFor="remote-allow"
                        >
                            {t('Allowed IP addresses')}
                        </label>
                        <textarea
                            id="remote-allow"
                            className={`textarea min-h-28 w-full font-mono ${errors.allow ? 'is-invalid' : ''}`}
                            placeholder={'203.0.113.7\n198.51.100.0/24'}
                            spellCheck={false}
                            autoComplete="off"
                            value={allow}
                            onChange={(e) => setAllow(e.target.value)}
                            aria-invalid={errors.allow ? true : undefined}
                        />
                        <p className="mt-1 text-sm text-base-content/70">
                            <Trans
                                text="The <b>public</b> address the connection comes from, one per line: your office, or your VPN server's. Ranges such as 198.51.100.0/24 work; nothing wider than /16 is accepted, and there is no “everybody”."
                                components={{ b: <strong /> }}
                            />
                        </p>
                        {errors.allow && (
                            <p className="mt-1 text-sm text-error" role="alert">
                                {errors.allow}
                            </p>
                        )}
                        {myIp !== null && (
                            <Button
                                variant="soft"
                                size="sm"
                                className="mt-2"
                                onClick={addMine}
                            >
                                {t('Add my address (:ip)', { ip: myIp })}
                            </Button>
                        )}
                    </div>

                    <Select
                        label={t('How long it stays open')}
                        value={duration}
                        onChange={(e) => setDuration(e.target.value)}
                        error={errors.duration}
                        hint={t(
                            'It closes completely at that time, even while someone is connected.',
                        )}
                    >
                        {durations.map((minutes) => (
                            <option key={minutes} value={String(minutes)}>
                                {durationLabel(minutes, t, tn)}
                            </option>
                        ))}
                        <option value={NEVER}>
                            {t('Never: stays open until I close it')}
                        </option>
                    </Select>

                    {never && (
                        <div className="space-y-3 rounded-box border-2 border-error bg-error/10 p-4">
                            <p className="flex items-center gap-2 font-semibold text-error">
                                <ShieldAlert
                                    className="size-5 shrink-0"
                                    aria-hidden
                                />
                                {t(
                                    'Very unsafe: it will stay open until you close it',
                                )}
                            </p>
                            <p className="text-sm">
                                {t(
                                    'Nothing closes it, not even a forgotten one. The addresses are all that stands between the internet and your database login, and an address you listed today can belong to someone else next year. Use this only for a fixed address you control, such as your own VPN server, and keep strong passwords.',
                                )}
                            </p>
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="checkbox mt-0.5 checkbox-error"
                                    checked={acknowledge}
                                    onChange={(e) =>
                                        setAcknowledge(e.target.checked)
                                    }
                                />
                                <span className="text-sm">
                                    {t(
                                        'I understand and accept the risk of leaving this database open to the internet.',
                                    )}
                                </span>
                            </label>
                            {errors.acknowledge && (
                                <p className="text-sm text-error" role="alert">
                                    {errors.acknowledge}
                                </p>
                            )}
                        </div>
                    )}

                    <ul className="list-disc space-y-1.5 pl-5 text-sm text-base-content/80">
                        <li>
                            <Trans
                                text="The port is chosen at random from <b>:from–:to</b> (never :port), and you see it once it is open. If your server or hosting provider has a firewall, you can open that whole range now, for the allowed addresses only."
                                components={{ b: <strong /> }}
                                values={{
                                    from: ports[0],
                                    to: ports[1],
                                    port:
                                        server.engine === 'postgres'
                                            ? '5432'
                                            : '3306',
                                }}
                            />
                        </li>
                        <li>
                            {t(
                                'Connections must use TLS. You get a certificate to check the server with.',
                            )}
                        </li>
                        <li>
                            {t(
                                "Cloudflare's proxy does not carry database traffic: use the server's own address, not a proxied (orange cloud) name.",
                            )}
                        </li>
                    </ul>

                    {needsRestart && (
                        <div className="space-y-2 rounded-box border border-warning bg-warning/10 p-4">
                            <p className="flex items-center gap-2 text-sm font-semibold">
                                <RotateCw
                                    className="size-4 shrink-0"
                                    aria-hidden
                                />
                                {t('The server restarts once')}
                                <Tooltip
                                    align="start"
                                    side="top"
                                    label={t(
                                        "TLS is a start-up setting of the database, so a running server cannot switch it on. It is needed once per server, and again only if the server's public address changes.",
                                    )}
                                >
                                    <button
                                        type="button"
                                        className="btn btn-circle btn-text btn-xs"
                                        aria-label={t(
                                            'Why does the server restart?',
                                        )}
                                    >
                                        <CircleHelp
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </button>
                                </Tooltip>
                            </p>
                            <p className="text-sm">
                                {t(
                                    'To require TLS, the database server is started again the first time. Your data stays. Apps linked to it lose their connection for a few seconds, and longer if the database has to recover, and reconnect by themselves.',
                                )}
                            </p>
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="checkbox mt-0.5 checkbox-warning"
                                    checked={acknowledgeRestart}
                                    onChange={(e) =>
                                        setAcknowledgeRestart(e.target.checked)
                                    }
                                />
                                <span className="text-sm">
                                    {t(
                                        'I understand the server restarts now and apps may briefly lose their connection.',
                                    )}
                                </span>
                            </label>
                            {errors.acknowledge_restart && (
                                <p className="text-sm text-error" role="alert">
                                    {errors.acknowledge_restart}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="flex flex-wrap justify-end gap-2">
                        <Button variant="ghost" onClick={onClose}>
                            {t('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            variant={never ? 'error' : 'primary'}
                            loading={busy}
                            disabled={
                                (never && !acknowledge) ||
                                (needsRestart && !acknowledgeRestart)
                            }
                        >
                            {changing
                                ? t('Save changes')
                                : never
                                  ? t('Open without an end')
                                  : t('Open')}
                        </Button>
                    </div>
                </form>
            </Modal>
            {confirmed.modal}
        </>
    );
}

/**
 * Whether the server can be reached from the internet, and from where. Closed unless somebody
 * opens it, for a time they choose.
 */
export function RemoteAccessCard({
    server,
    remote,
    durations,
    ports,
    myIp,
    canChange,
}: {
    server: DatabaseServer;
    remote: RemoteAccess;
    durations: number[];
    ports: [number, number];
    myIp: string | null;
    canChange: boolean;
}) {
    const { t } = useT();
    const confirmed = useConfirmedAction();
    const [modal, setModal] = useState<'open' | 'change' | null>(null);
    const [busy, setBusy] = useState(false);
    const left = useTimeLeft(remote.forever ? null : remote.expires_at);

    const isOpen = remote.status === 'open';
    const opening = remote.status === 'opening';
    const failed = remote.status === 'failed';
    const active = isOpen || opening || failed;

    const close = () =>
        void confirmed.run(() =>
            router.delete(DatabaseRemoteController.destroy(server.slug).url, {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            }),
        );

    return (
        <Card
            title={t('Remote access')}
            description={t(
                'Reach this server from outside, for backups or tools on your own network. Closed unless you open it.',
            )}
            className={isOpen ? 'border-2 border-error' : undefined}
            actions={
                canChange &&
                server.status === 'running' &&
                !active && (
                    <Button
                        size="sm"
                        variant="soft"
                        onClick={() => setModal('open')}
                    >
                        <LockOpen className="size-4" aria-hidden />
                        {t('Open…')}
                    </Button>
                )
            }
        >
            {!active && (
                <div className="flex items-start gap-3">
                    <Lock
                        className="mt-0.5 size-5 shrink-0 text-success"
                        aria-hidden
                    />
                    <p className="text-sm text-base-content/80">
                        <Trans
                            text="<b>Closed.</b> The server has no port on the internet. Only apps linked to its databases can reach it."
                            components={{ b: <strong /> }}
                        />
                    </p>
                </div>
            )}

            {opening && (
                <div className="flex items-center gap-3 text-sm" role="status">
                    <LoaderCircle
                        className="size-5 animate-spin text-primary"
                        aria-hidden
                    />
                    {t(
                        'Opening. If the server has to start again with TLS this takes a moment.',
                    )}
                </div>
            )}

            {failed && (
                <Alert variant="error">
                    <span className="flex flex-wrap items-center justify-between gap-3">
                        <span className="text-sm">
                            {t('Could not open it: :error', {
                                error:
                                    remote.error ?? t('something went wrong.'),
                            })}
                        </span>
                        {canChange && (
                            <Button
                                size="sm"
                                variant="soft"
                                loading={busy}
                                onClick={close}
                            >
                                {t('Clear')}
                            </Button>
                        )}
                    </span>
                </Alert>
            )}

            {isOpen && (
                <div className="space-y-4">
                    <Alert variant="error">
                        <span className="flex items-center gap-2 text-sm">
                            <ShieldAlert
                                className="size-5 shrink-0"
                                aria-hidden
                            />
                            <span>
                                {remote.forever ? (
                                    <Trans
                                        text="<b>Open to the internet</b> with no end. Close it when you no longer need it."
                                        components={{ b: <strong /> }}
                                    />
                                ) : (
                                    <Trans
                                        text="<b>Open to the internet</b> until it closes by itself."
                                        components={{ b: <strong /> }}
                                    />
                                )}
                            </span>
                        </span>
                    </Alert>

                    <dl className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="flex items-center gap-1.5 text-sm text-base-content/70">
                                <Globe className="size-4" aria-hidden />{' '}
                                {t('Address')}
                            </dt>
                            <dd className="mt-1">
                                <code className="rounded-field bg-base-200 px-2 py-1 text-sm break-all">
                                    {remote.host}:{remote.port}
                                </code>
                            </dd>
                        </div>
                        <div>
                            <dt className="flex items-center gap-1.5 text-sm text-base-content/70">
                                <Clock className="size-4" aria-hidden />{' '}
                                {t('Open until')}
                            </dt>
                            <dd className="mt-1 text-sm">
                                {remote.forever ? (
                                    <span className="badge-soft badge badge-error">
                                        {t('Never closes')}
                                    </span>
                                ) : (
                                    <>
                                        {formatDate(remote.expires_at)}{' '}
                                        <span className="text-base-content/70">
                                            ({left})
                                        </span>
                                    </>
                                )}
                            </dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-sm text-base-content/70">
                                {t('Only these addresses may connect')}
                            </dt>
                            <dd className="mt-1 flex flex-wrap gap-1.5">
                                {remote.allow.map((entry) => (
                                    <code
                                        key={entry}
                                        className="badge-soft badge-neutral badge"
                                    >
                                        {entry}
                                    </code>
                                ))}
                            </dd>
                        </div>
                    </dl>

                    {canChange && (
                        <div className="space-y-2">
                            <p className="text-sm text-base-content/70">
                                {t(
                                    'Connect with TLS and check the server with its certificate:',
                                )}
                            </p>
                            <CommandBox
                                command={connectionCommand(server, remote)}
                            />
                            <p className="text-sm text-base-content/70">
                                {t(
                                    "Use the database's own user and password (show it from the database list). The certificate covers this address.",
                                )}
                            </p>
                        </div>
                    )}

                    {remote.opened_by && (
                        <p className="text-sm text-base-content/60">
                            {remote.opened_at
                                ? t('Opened by :name, :date.', {
                                      name: remote.opened_by,
                                      date: formatDate(remote.opened_at),
                                  })
                                : t('Opened by :name.', {
                                      name: remote.opened_by,
                                  })}
                        </p>
                    )}

                    {canChange && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="soft"
                                onClick={() => setModal('change')}
                            >
                                {t('Change addresses or time')}
                            </Button>
                            <a
                                className="btn btn-soft"
                                href={
                                    DatabaseRemoteController.ca(server.slug).url
                                }
                            >
                                <Download className="size-4" aria-hidden />
                                {t('Certificate (ca.pem)')}
                            </a>
                            <Button
                                variant="error"
                                loading={busy}
                                onClick={close}
                            >
                                {t('Close now')}
                            </Button>
                        </div>
                    )}
                </div>
            )}

            {modal !== null && (
                <OpenModal
                    server={server}
                    remote={remote}
                    durations={durations}
                    ports={ports}
                    myIp={myIp}
                    changing={modal === 'change'}
                    onClose={() => setModal(null)}
                />
            )}
            {confirmed.modal}
        </Card>
    );
}
