import { Check, Circle, Loader2, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import DatabaseToolController from '@/actions/App/Http/Controllers/DatabaseToolController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { jsonHeaders } from '@/lib/post-json';
import { cn } from '@/lib/utils';

type Props = {
    server: { slug: string; name: string };
    database: { id: number; name: string; user: string };
    tool: string;
    idle_minutes: number;
};

type Stage = 'pulling' | 'starting' | 'waiting' | 'ready' | 'opening';
const STEPS: { stage: Stage; label: (tool: string) => string }[] = [
    {
        stage: 'pulling',
        label: (tool) =>
            `Getting ${tool} ready (a download the first time, a moment after that)`,
    },
    { stage: 'starting', label: () => 'Starting it next to your database' },
    { stage: 'waiting', label: () => 'Waiting for it to answer' },
    { stage: 'opening', label: () => 'Opening it in this tab' },
];

async function call(
    method: 'GET' | 'POST',
    url: string,
): Promise<{ status: number; data: Record<string, unknown> }> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: jsonHeaders(),
        ...(method === 'POST' ? { body: '{}' } : {}),
    });

    return {
        status: response.status,
        data: (await response.json().catch(() => ({}))) as Record<
            string,
            unknown
        >,
    };
}

const sleep = (ms: number) => new Promise((done) => setTimeout(done, ms));

/**
 * Opens a database in phpMyAdmin or Adminer. This page is in a new tab: it asks the
 * server to start the tool, shows how far that is, and then moves this tab on to the
 * tool, already logged in. It tells what is about to happen, including that the tool
 * closes by itself.
 */
export default function Tool({ server, database, tool, idle_minutes }: Props) {
    const [stage, setStage] = useState<Stage>('pulling');
    const [error, setError] = useState<string | null>(null);
    const [closed, setClosed] = useState(false);
    const session = useRef<string | null>(null);
    const cancelled = useRef(false);
    const started = useRef(false);

    const ids = { server: server.slug, database: database.id };

    useEffect(() => {
        if (started.current) return;
        started.current = true;

        const run = async () => {
            const start = await call(
                'POST',
                DatabaseToolController.start(ids).url,
            );

            if (start.status === 423) {
                // The password confirmation ran out while this page was open: ask again.
                window.location.reload();

                return;
            }

            if (start.status !== 200) {
                setError(
                    (start.data.error as string | undefined) ??
                        `${tool} could not be started.`,
                );

                return;
            }

            session.current = start.data.session as string;
            const sessionIds = { ...ids, session: session.current };
            const deadline = Date.now() + 6 * 60 * 1000;

            while (!cancelled.current) {
                await sleep(1200);

                const status = await call(
                    'GET',
                    DatabaseToolController.status(sessionIds).url,
                );

                if (status.status === 404) {
                    setError(`${tool} closed before it was ready. Try again.`);

                    return;
                }

                if (status.status !== 200) {
                    setError(
                        (status.data.error as string | undefined) ??
                            'The server did not answer.',
                    );

                    return;
                }

                const current = status.data.stage as string;

                if (current === 'failed') {
                    setError(
                        (status.data.error as string | undefined) ??
                            `${tool} could not be started.`,
                    );

                    return;
                }

                if (current === 'ready') break;

                setStage(current as Stage);

                if (Date.now() > deadline) {
                    setError(`${tool} is taking too long to start.`);

                    return;
                }
            }

            if (cancelled.current) return;

            setStage('opening');
            const open = await call(
                'POST',
                DatabaseToolController.open(sessionIds).url,
            );

            if (open.status !== 200 || typeof open.data.url !== 'string') {
                setError(
                    (open.data.error as string | undefined) ??
                        `${tool} could not be opened.`,
                );

                return;
            }

            window.location.replace(open.data.url);
        };

        void run().catch(() =>
            setError('The connection to CommyPanel was lost. Try again.'),
        );

        return () => {
            cancelled.current = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const cancel = async () => {
        cancelled.current = true;

        if (session.current) {
            await call(
                'POST',
                DatabaseToolController.stop({
                    ...ids,
                    session: session.current,
                }).url,
            ).catch(() => undefined);
        }

        setClosed(true);
    };

    const order = STEPS.findIndex((step) => step.stage === stage);

    return (
        <AuthLayout
            title={`Opening ${tool}`}
            description={`${database.name} on ${server.name}`}
        >
            {closed ? (
                <div className="space-y-4">
                    <p className="text-sm">
                        Cancelled. {tool} was not opened. You can close this
                        tab.
                    </p>
                </div>
            ) : error ? (
                <div className="space-y-4">
                    <Alert variant="error">
                        <span className="flex items-start gap-2">
                            <TriangleAlert
                                className="mt-0.5 size-4 shrink-0"
                                aria-hidden
                            />
                            {error}
                        </span>
                    </Alert>
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => window.location.reload()}>
                            Try again
                        </Button>
                    </div>
                    <p className="text-sm text-base-content/70">
                        You can close this tab. Nothing is left running.
                    </p>
                </div>
            ) : (
                <div className="space-y-5">
                    <ol className="space-y-3" aria-label="Progress">
                        {STEPS.map((step, index) => {
                            const done = index < order;
                            const current = index === order;

                            return (
                                <li
                                    key={step.stage}
                                    className={cn(
                                        'flex items-start gap-3 text-sm',
                                        !done &&
                                            !current &&
                                            'text-base-content/60',
                                    )}
                                    aria-current={current ? 'step' : undefined}
                                >
                                    <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center">
                                        {done ? (
                                            <Check
                                                className="size-4 text-success"
                                                aria-hidden
                                            />
                                        ) : current ? (
                                            <Loader2
                                                className="size-4 animate-spin text-primary"
                                                aria-hidden
                                            />
                                        ) : (
                                            <Circle
                                                className="size-3"
                                                aria-hidden
                                            />
                                        )}
                                    </span>
                                    <span
                                        className={cn(current && 'font-medium')}
                                    >
                                        {step.label(tool)}
                                    </span>
                                </li>
                            );
                        })}
                    </ol>

                    <ul className="list-disc space-y-1.5 pl-5 text-sm text-base-content/80">
                        <li>
                            {tool} is started for you alone, logged in as{' '}
                            <code>{database.user}</code>, so you see this
                            database and nothing else on the server.
                        </li>
                        <li>
                            It closes by itself after{' '}
                            <strong>
                                {idle_minutes} minutes without activity
                            </strong>
                            , and is then deleted. Nothing stays installed.
                            Opening the database again starts a fresh one.
                        </li>
                        <li>
                            Everything you do there happens to the real
                            database, and is not undone by closing the tab.
                        </li>
                    </ul>

                    <Button variant="ghost" onClick={() => void cancel()}>
                        Cancel
                    </Button>
                </div>
            )}
        </AuthLayout>
    );
}
