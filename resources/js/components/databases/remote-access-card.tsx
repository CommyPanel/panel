import { router } from '@inertiajs/react';
import {
    Clock,
    Download,
    Globe,
    LoaderCircle,
    Lock,
    LockOpen,
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
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { formatDate } from '@/lib/format';
import type { DatabaseServer, RemoteAccess } from '@/types';

const NEVER = 'never';

function durationLabel(minutes: number): string {
    if (minutes < 60) return `${minutes} minutes`;
    if (minutes < 1440)
        return `${minutes / 60} hour${minutes === 60 ? '' : 's'}`;

    return `${minutes / 1440} day${minutes === 1440 ? '' : 's'}`;
}

/** "12 min left", "3 h 5 min left" and so on, kept fresh while the page is open. */
function useTimeLeft(iso: string | null): string | null {
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

    if (seconds < 60) return 'less than a minute left';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} min left`;
    const hours = Math.floor(minutes / 60);
    if (hours < 48) return `${hours} h ${minutes % 60} min left`;

    return `${Math.floor(hours / 24)} days left`;
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
    myIp,
    changing,
    onClose,
}: {
    server: DatabaseServer;
    remote: RemoteAccess;
    durations: number[];
    myIp: string | null;
    changing: boolean;
    onClose: () => void;
}) {
    const confirmed = useConfirmedAction();
    const [allow, setAllow] = useState(changing ? remote.allow.join('\n') : '');
    const [duration, setDuration] = useState<string>(
        changing && remote.forever ? NEVER : '60',
    );
    const [acknowledge, setAcknowledge] = useState(false);
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
                        ? 'Change who may connect'
                        : 'Open this server to the internet'
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
                                    This makes the database server reachable
                                    from outside. Only the addresses you list
                                    can connect, and only with TLS, but anyone
                                    holding a database password and coming from
                                    one of them can log in. Prefer a short time,
                                    and a VPN or an SSH tunnel for anything
                                    permanent.
                                </span>
                            </span>
                        </Alert>
                    )}

                    <div className="w-full">
                        <label
                            className="mb-1 label-text block px-0!"
                            htmlFor="remote-allow"
                        >
                            Allowed IP addresses
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
                            The <strong>public</strong> address the connection
                            comes from, one per line: your office, or your VPN
                            server&apos;s. Ranges such as 198.51.100.0/24 work;
                            nothing wider than /16 is accepted, and there is no
                            &ldquo;everybody&rdquo;.
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
                                Add my address ({myIp})
                            </Button>
                        )}
                    </div>

                    <Select
                        label="How long it stays open"
                        value={duration}
                        onChange={(e) => setDuration(e.target.value)}
                        error={errors.duration}
                        hint="It closes completely at that time, even while someone is connected."
                    >
                        {durations.map((minutes) => (
                            <option key={minutes} value={String(minutes)}>
                                {durationLabel(minutes)}
                            </option>
                        ))}
                        <option value={NEVER}>
                            Never: stays open until I close it
                        </option>
                    </Select>

                    {never && (
                        <div className="space-y-3 rounded-box border-2 border-error bg-error/10 p-4">
                            <p className="flex items-center gap-2 font-semibold text-error">
                                <ShieldAlert
                                    className="size-5 shrink-0"
                                    aria-hidden
                                />
                                Very unsafe: it will stay open until you close
                                it
                            </p>
                            <p className="text-sm">
                                Nothing closes it, not even a forgotten one. The
                                addresses are all that stands between the
                                internet and your database login, and an address
                                you listed today can belong to someone else next
                                year. Use this only for a fixed address you
                                control, such as your own VPN server, and keep
                                strong passwords.
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
                                    I understand and accept the risk of leaving
                                    this database open to the internet.
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
                            The port is chosen at random (not{' '}
                            {server.engine === 'postgres' ? '5432' : '3306'}).
                            If your server has a firewall, open it for the
                            allowed addresses.
                        </li>
                        <li>
                            Connections must use TLS. You get a certificate to
                            check the server with.
                            {!remote.tls && (
                                <>
                                    {' '}
                                    <strong>
                                        The first time, the server restarts once
                                        to switch TLS on.
                                    </strong>{' '}
                                    Linked apps reconnect by themselves.
                                </>
                            )}
                        </li>
                        <li>
                            Cloudflare&apos;s proxy does not carry database
                            traffic: use the server&apos;s own address, not a
                            proxied (orange cloud) name.
                        </li>
                    </ul>

                    <div className="flex flex-wrap justify-end gap-2">
                        <Button variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={never ? 'error' : 'primary'}
                            loading={busy}
                            disabled={never && !acknowledge}
                        >
                            {changing
                                ? 'Save changes'
                                : never
                                  ? 'Open without an end'
                                  : 'Open'}
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
    myIp,
    canChange,
}: {
    server: DatabaseServer;
    remote: RemoteAccess;
    durations: number[];
    myIp: string | null;
    canChange: boolean;
}) {
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
            title="Remote access"
            description="Reach this server from outside, for backups or tools on your own network. Closed unless you open it."
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
                        Open…
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
                        <strong>Closed.</strong> The server has no port on the
                        internet. Only apps linked to its databases can reach
                        it.
                    </p>
                </div>
            )}

            {opening && (
                <div className="flex items-center gap-3 text-sm" role="status">
                    <LoaderCircle
                        className="size-5 animate-spin text-primary"
                        aria-hidden
                    />
                    Opening. If the server has to start again with TLS this
                    takes a moment.
                </div>
            )}

            {failed && (
                <Alert variant="error">
                    <span className="flex flex-wrap items-center justify-between gap-3">
                        <span className="text-sm">
                            Could not open it:{' '}
                            {remote.error ?? 'something went wrong.'}
                        </span>
                        {canChange && (
                            <Button
                                size="sm"
                                variant="soft"
                                loading={busy}
                                onClick={close}
                            >
                                Clear
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
                                <strong>Open to the internet</strong>
                                {remote.forever
                                    ? ' with no end. Close it when you no longer need it.'
                                    : ' until it closes by itself.'}
                            </span>
                        </span>
                    </Alert>

                    <dl className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="flex items-center gap-1.5 text-sm text-base-content/70">
                                <Globe className="size-4" aria-hidden /> Address
                            </dt>
                            <dd className="mt-1">
                                <code className="rounded-field bg-base-200 px-2 py-1 text-sm break-all">
                                    {remote.host}:{remote.port}
                                </code>
                            </dd>
                        </div>
                        <div>
                            <dt className="flex items-center gap-1.5 text-sm text-base-content/70">
                                <Clock className="size-4" aria-hidden /> Open
                                until
                            </dt>
                            <dd className="mt-1 text-sm">
                                {remote.forever ? (
                                    <span className="badge-soft badge badge-error">
                                        Never closes
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
                                Only these addresses may connect
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
                                Connect with TLS and check the server with its
                                certificate:
                            </p>
                            <CommandBox
                                command={connectionCommand(server, remote)}
                            />
                            <p className="text-sm text-base-content/70">
                                Use the database&apos;s own user and password
                                (show it from the database list). The
                                certificate covers this address.
                            </p>
                        </div>
                    )}

                    {remote.opened_by && (
                        <p className="text-sm text-base-content/60">
                            Opened by {remote.opened_by}
                            {remote.opened_at
                                ? `, ${formatDate(remote.opened_at)}`
                                : ''}
                            .
                        </p>
                    )}

                    {canChange && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="soft"
                                onClick={() => setModal('change')}
                            >
                                Change addresses or time
                            </Button>
                            <a
                                className="btn btn-soft"
                                href={
                                    DatabaseRemoteController.ca(server.slug).url
                                }
                            >
                                <Download className="size-4" aria-hidden />
                                Certificate (ca.pem)
                            </a>
                            <Button
                                variant="error"
                                loading={busy}
                                onClick={close}
                            >
                                Close now
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
                    myIp={myIp}
                    changing={modal === 'change'}
                    onClose={() => setModal(null)}
                />
            )}
            {confirmed.modal}
        </Card>
    );
}
