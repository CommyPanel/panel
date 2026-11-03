import { router, useForm } from '@inertiajs/react';
import { Pencil, Wand2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import ServerAddressController from '@/actions/App/Http/Controllers/ServerAddressController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import type { ServerAddress } from '@/types';

/**
 * The address is found by the panel itself, in the background. While that
 * runs, ask again every couple of seconds (every page keeps it in `server`).
 */
function useWhileDetecting(server: ServerAddress) {
    useEffect(() => {
        if (!server.detecting) return;

        const timer = setInterval(
            () => router.reload({ only: ['server'] }),
            2000,
        );

        return () => clearInterval(timer);
    }, [server.detecting]);
}

function sourceText(server: ServerAddress): string {
    return server.source === 'auto'
        ? 'Found automatically on this server.'
        : 'Entered by you.';
}

/** The two fields, with a way to find the address again. */
function ServerAddressForm({
    server,
    onSaved,
    onCancel,
}: {
    server: ServerAddress;
    onSaved?: () => void;
    onCancel?: () => void;
}) {
    const form = useForm({ ipv4: server.ipv4 ?? '', ipv6: server.ipv6 ?? '' });
    const [detecting, setDetecting] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(ServerAddressController.update(), {
            preserveScroll: true,
            onSuccess: () => onSaved?.(),
        });
    };

    const detectAgain = () =>
        router.post(
            ServerAddressController.detect(),
            {},
            {
                preserveScroll: true,
                onStart: () => setDetecting(true),
                onFinish: () => setDetecting(false),
                onSuccess: () => onSaved?.(),
            },
        );

    return (
        <form onSubmit={submit} className="space-y-4">
            <Input
                label="IPv4 address"
                placeholder="203.0.113.7"
                autoComplete="off"
                spellCheck={false}
                value={form.data.ipv4}
                onChange={(e) => form.setData('ipv4', e.target.value)}
                error={form.errors.ipv4}
            />
            <Input
                label="IPv6 address (optional)"
                placeholder="2001:db8::7"
                autoComplete="off"
                spellCheck={false}
                value={form.data.ipv6}
                onChange={(e) => form.setData('ipv6', e.target.value)}
                error={form.errors.ipv6}
                hint="Use the server's public address, not a private one from behind a router or load balancer."
            />
            <div className="flex flex-wrap gap-2">
                <Button type="submit" loading={form.processing}>
                    Save address
                </Button>
                <Button
                    variant="soft"
                    onClick={detectAgain}
                    loading={detecting}
                >
                    <Wand2 className="size-4" aria-hidden />
                    Detect again
                </Button>
                {onCancel && (
                    <Button variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                )}
            </div>
        </form>
    );
}

/**
 * The server's address as a card: found automatically, with a way to correct
 * it, or the form straight away when it could not be found.
 */
export function ServerAddressCard({ server }: { server: ServerAddress }) {
    const [editing, setEditing] = useState(false);
    useWhileDetecting(server);

    const configured = server.ipv4 !== null || server.ipv6 !== null;

    return (
        <Card
            title="Server address"
            description="Your domains must point at these addresses."
        >
            {server.detecting && (
                <p className="text-sm text-base-content/70" role="status">
                    Finding this server&apos;s public address…
                </p>
            )}

            {!configured && !server.detecting && (
                <>
                    <Alert variant="warning">
                        The address of this server could not be found
                        automatically. It may sit behind a router or a load
                        balancer. Enter its public address below.
                    </Alert>
                    <ServerAddressForm server={server} />
                </>
            )}

            {configured && !editing && (
                <div className="space-y-3">
                    <dl className="divide-y divide-base-300 text-sm">
                        {server.ipv4 && (
                            <div className="flex justify-between gap-4 py-2">
                                <dt className="text-base-content/70">IPv4</dt>
                                <dd className="font-mono">{server.ipv4}</dd>
                            </div>
                        )}
                        {server.ipv6 && (
                            <div className="flex justify-between gap-4 py-2">
                                <dt className="text-base-content/70">IPv6</dt>
                                <dd className="font-mono">{server.ipv6}</dd>
                            </div>
                        )}
                    </dl>
                    <p className="text-sm text-base-content/70">
                        {sourceText(server)}
                    </p>
                    <Button
                        variant="soft"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil className="size-4" aria-hidden />
                        {server.source === 'auto'
                            ? 'Wrong IPs detected?'
                            : 'Change the addresses'}
                    </Button>
                </div>
            )}

            {configured && editing && (
                <ServerAddressForm
                    server={server}
                    onSaved={() => setEditing(false)}
                    onCancel={() => setEditing(false)}
                />
            )}
        </Card>
    );
}

/**
 * A line for the places that need the address (the DNS records of a domain):
 * what it is and where it came from, with a way to correct it without leaving
 * the page.
 */
export function ServerAddressNote({ server }: { server: ServerAddress }) {
    const [open, setOpen] = useState(false);
    useWhileDetecting(server);

    const configured = server.ipv4 !== null || server.ipv6 !== null;

    return (
        <div className="text-sm text-base-content/70">
            {server.detecting && (
                <span role="status">
                    Finding this server&apos;s public address…
                </span>
            )}
            {!configured && !server.detecting && (
                <Alert variant="warning">
                    <span>
                        The address of this server could not be found
                        automatically, so the DNS records cannot be shown.{' '}
                        <button
                            type="button"
                            className="link font-medium"
                            onClick={() => setOpen(true)}
                        >
                            Enter the address
                        </button>
                    </span>
                </Alert>
            )}
            {configured && (
                <span>
                    Domains must point at{' '}
                    <strong className="font-mono font-medium">
                        {[server.ipv4, server.ipv6]
                            .filter(Boolean)
                            .join(' and ')}
                    </strong>
                    . {sourceText(server)}{' '}
                    <button
                        type="button"
                        className="link font-medium"
                        onClick={() => setOpen(true)}
                    >
                        {server.source === 'auto'
                            ? 'Wrong IPs detected?'
                            : 'Change'}
                    </button>
                </span>
            )}
            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title="Server address"
            >
                <div className="space-y-4">
                    <p className="text-sm">
                        Domains are connected by checking that their DNS records
                        point at these addresses. Correct them if the wrong ones
                        were found.
                    </p>
                    <ServerAddressForm
                        server={server}
                        onSaved={() => setOpen(false)}
                        onCancel={() => setOpen(false)}
                    />
                </div>
            </Modal>
        </div>
    );
}
