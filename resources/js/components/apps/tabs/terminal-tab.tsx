import { FitAddon } from '@xterm/addon-fit';
import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { Plug, PlugZap, TerminalSquare } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import AppTerminalController from '@/actions/App/Http/Controllers/AppTerminalController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { jsonHeaders } from '@/lib/post-json';
import type { AppSettings } from '@/types';

type Status = 'idle' | 'connecting' | 'open' | 'closed';

type Ticket = { ticket: string; path: string; container: string };

// A terminal stays dark in every theme, like the ones people are used to.
const THEME = {
    background: '#0c0a09',
    foreground: '#e7e5e4',
    cursor: '#fb923c',
    selectionBackground: '#44403c',
};

async function requestTicket(slug: string): Promise<Ticket> {
    const response = await fetch(AppTerminalController.ticket(slug).url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: jsonHeaders(),
        body: '{}',
    });
    const body = (await response
        .json()
        .catch(() => ({}))) as Partial<Ticket> & {
        error?: string;
        message?: string;
    };

    if (response.status === 423) {
        throw new Error('Confirm your password and try again.');
    }
    if (response.status === 429) {
        throw new Error('Too many attempts. Wait a minute and try again.');
    }
    if (!response.ok || !body.ticket || !body.path) {
        throw new Error(body.error ?? 'The terminal could not be opened.');
    }

    return body as Ticket;
}

/**
 * A shell in the app's running container. The panel hands out a single-use
 * ticket after the password check; the browser then opens a WebSocket that
 * nginx passes to the agent. Closing the tab or navigating away ends the
 * session. Only mounted while the tab is open.
 */
export function TerminalTab({ app }: { app: AppSettings }) {
    const confirmed = useConfirmedAction();
    const host = useRef<HTMLDivElement>(null);
    const term = useRef<Terminal | null>(null);
    const fit = useRef<FitAddon | null>(null);
    const socket = useRef<WebSocket | null>(null);
    const [status, setStatus] = useState<Status>('idle');
    const [message, setMessage] = useState<string | null>(null);

    const running = app.status === 'running';

    const teardown = useCallback(() => {
        socket.current?.close();
        socket.current = null;
        term.current?.dispose();
        term.current = null;
        fit.current = null;
    }, []);

    useEffect(() => teardown, [teardown]);

    // Keep the terminal sized to its box, and tell the container.
    useEffect(() => {
        const el = host.current;

        if (!el) return;

        const observer = new ResizeObserver(() => {
            try {
                fit.current?.fit();
            } catch {
                // Not laid out yet.
            }
        });
        observer.observe(el);

        return () => observer.disconnect();
    }, []);

    const connect = async () => {
        teardown();
        setMessage(null);
        setStatus('connecting');

        let ticket: Ticket;

        try {
            ticket = await requestTicket(app.slug);
        } catch (e) {
            setStatus('idle');
            setMessage(
                e instanceof Error
                    ? e.message
                    : 'The terminal could not be opened.',
            );

            return;
        }

        const el = host.current;

        if (!el) return;

        const xterm = new Terminal({
            cursorBlink: true,
            fontFamily:
                'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
            fontSize: 13,
            scrollback: 5000,
            theme: THEME,
        });
        const fitAddon = new FitAddon();
        xterm.loadAddon(fitAddon);
        xterm.open(el);
        fitAddon.fit();
        term.current = xterm;
        fit.current = fitAddon;

        const scheme = window.location.protocol === 'https:' ? 'wss' : 'ws';
        const url = `${scheme}://${window.location.host}${ticket.path}?ticket=${encodeURIComponent(ticket.ticket)}&cols=${xterm.cols}&rows=${xterm.rows}`;
        const ws = new WebSocket(url);
        ws.binaryType = 'arraybuffer';
        socket.current = ws;
        const encoder = new TextEncoder();

        ws.onopen = () => {
            setStatus('open');
            xterm.focus();
        };
        ws.onmessage = (event) => {
            if (typeof event.data === 'string') {
                try {
                    const control = JSON.parse(event.data) as {
                        type: string;
                        message?: string;
                    };

                    if (control.type === 'error') {
                        setMessage(control.message ?? 'The terminal failed.');
                    } else if (control.type === 'exit') {
                        setMessage('The shell exited.');
                    }
                } catch {
                    // Not for us.
                }

                return;
            }

            xterm.write(new Uint8Array(event.data as ArrayBuffer));
        };
        ws.onclose = (event) => {
            setStatus('closed');
            setMessage(
                (m) =>
                    m ??
                    (event.reason
                        ? `The session ended: ${event.reason}.`
                        : event.code === 1006 || event.code === 1015
                          ? 'The connection could not be made, or was lost. The ticket may have expired; open the terminal again.'
                          : 'The session ended.'),
            );
        };

        xterm.onData((data) => {
            if (ws.readyState === WebSocket.OPEN) ws.send(encoder.encode(data));
        });
        xterm.onResize(({ cols, rows }) => {
            if (ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({ type: 'resize', cols, rows }));
            }
        });
    };

    const open = () => void confirmed.run(() => void connect());

    const disconnect = () => {
        socket.current?.close(1000, 'closed by you');
    };

    if (!running) {
        return (
            <Card
                title="Terminal"
                description="A shell inside the running container."
            >
                <p className="text-sm text-base-content/70">
                    A terminal needs a running container. Start the app first.
                </p>
            </Card>
        );
    }

    const active = status === 'connecting' || status === 'open';

    return (
        <div className="space-y-6">
            <Card
                title="Terminal"
                description="A shell inside the running container, as the user the app runs as and with its environment variables."
                actions={
                    active ? (
                        <Button variant="soft" size="sm" onClick={disconnect}>
                            <Plug className="size-4" aria-hidden />
                            Disconnect
                        </Button>
                    ) : (
                        <Button size="sm" onClick={open}>
                            <PlugZap className="size-4" aria-hidden />
                            {status === 'closed'
                                ? 'Open again'
                                : 'Open terminal'}
                        </Button>
                    )
                }
            >
                {message && (
                    <Alert variant={status === 'idle' ? 'error' : 'info'}>
                        {message}
                    </Alert>
                )}

                {status === 'idle' && !message && (
                    <p className="flex items-center gap-2 text-sm text-base-content/70">
                        <TerminalSquare className="size-4" aria-hidden />
                        Press Open terminal to start a session. You may be asked
                        for your password.
                    </p>
                )}

                <div
                    ref={host}
                    aria-label="Terminal"
                    className={
                        status === 'idle'
                            ? 'hidden'
                            : 'h-[28rem] overflow-hidden rounded-field p-2'
                    }
                    style={{ background: THEME.background }}
                />

                <ul className="list-disc space-y-1 pl-5 text-sm text-base-content/70">
                    <li>
                        Opening a terminal is recorded in the audit log; what
                        you type is not.
                    </li>
                    <li>
                        Changes you make inside the container are lost at the
                        next deployment, except in persistent volumes.
                    </li>
                    <li>
                        A session ends after 15 minutes without input or output,
                        or after 2 hours. Some images have no shell; then the
                        terminal says so.
                    </li>
                </ul>
            </Card>
            {confirmed.modal}
        </div>
    );
}
