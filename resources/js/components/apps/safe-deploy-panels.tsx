import { router } from '@inertiajs/react';
import {
    ArrowRight,
    CheckCircle2,
    Hourglass,
    Pause,
    Play,
    ShieldCheck,
    Split,
    SquareX,
    TriangleAlert,
    Undo2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import DeploymentController from '@/actions/App/Http/Controllers/DeploymentController';
import RolloutController from '@/actions/App/Http/Controllers/RolloutController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { Modal } from '@/components/ui/modal';
import { cn } from '@/lib/utils';
import type { DeploymentDetail, SafeDeployInfo, SafeStat } from '@/types';

type Traffic = SafeDeployInfo['traffic'];

/** The time now, refreshed every few seconds, for countdowns. */
function useNow(ms = 5000): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), ms);

        return () => clearInterval(timer);
    }, [ms]);

    return now;
}

function minutesLeft(iso: string | null, now: number): string {
    if (!iso) return '';

    const seconds = Math.max(0, Math.round((Date.parse(iso) - now) / 1000));

    if (seconds < 60) return `${seconds} s`;

    return `${Math.ceil(seconds / 60)} min`;
}

function clock(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleTimeString([], {
              hour: '2-digit',
              minute: '2-digit',
          })
        : '';
}

const fmt = (n: number) => n.toLocaleString();

function Verdict({ traffic }: { traffic: NonNullable<Traffic> }) {
    const { state, reason } = traffic.verdict;
    const Icon =
        state === 'ok'
            ? CheckCircle2
            : state === 'bad'
              ? TriangleAlert
              : Hourglass;
    const tone =
        state === 'ok'
            ? 'text-success'
            : state === 'bad'
              ? 'text-error'
              : 'text-base-content/70';

    return (
        <p className={cn('flex items-start gap-2 text-sm', tone)}>
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span>{reason}</span>
        </p>
    );
}

/** This release against the one before it, over the same time. */
function Comparison({
    traffic,
    previous,
}: {
    traffic: NonNullable<Traffic>;
    previous: number | null;
}) {
    const rows: [string, (s: SafeStat) => string][] = [
        ['Requests', (s) => fmt(s.requests)],
        ['Errors (5xx)', (s) => `${s.error_percent}%`],
        ['Slowest 5%', (s) => `${fmt(s.p95_ms)} ms`],
    ];

    return (
        <div className="space-y-3">
            <div className="overflow-x-auto">
                <table className="table table-sm">
                    <thead>
                        <tr>
                            <th />
                            <th className="tracking-normal normal-case">
                                This release
                            </th>
                            <th className="tracking-normal normal-case">
                                {previous ? `Release #${previous}` : 'Before'}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map(([label, show]) => (
                            <tr key={label}>
                                <td className="text-base-content/70">
                                    {label}
                                </td>
                                <td className="font-medium">
                                    {traffic.new ? show(traffic.new) : '-'}
                                </td>
                                <td>
                                    {traffic.reference
                                        ? show(traffic.reference)
                                        : '-'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Verdict traffic={traffic} />
        </div>
    );
}

/** Going live again with a release that was rolled back, on purpose. */
export function RetryModal({
    open,
    onClose,
    slug,
    number,
}: {
    open: boolean;
    onClose: () => void;
    slug: string;
    number: number;
}) {
    const [watch, setWatch] = useState(true);
    const [busy, setBusy] = useState(false);

    const go = () =>
        router.post(
            DeploymentController.retry({ app: slug, deployment: number }).url,
            { watch },
            {
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    onClose();
                },
            },
        );

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={`Go live again with release #${number}?`}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={go} loading={busy}>
                        <Play className="size-4" aria-hidden />
                        Go live again
                    </Button>
                </>
            }
        >
            <div className="space-y-4 text-sm">
                <p>
                    This release did clearly worse than the one before it, so
                    CommyPanel moved the traffic back. If you want to try it
                    again, it is started from the image that is already built
                    (nothing is built) and takes all the traffic like a normal
                    deployment, with no downtime.
                </p>
                <label className="flex cursor-pointer items-start gap-3 rounded-field border border-base-300 p-3">
                    <input
                        type="checkbox"
                        className="checkbox mt-0.5"
                        checked={watch}
                        onChange={(e) => setWatch(e.target.checked)}
                    />
                    <span>
                        <span className="font-medium">
                            Watch it again, and roll back by itself if it does
                            worse
                        </span>
                        <span className="block text-base-content/70">
                            Untick this if you know why it failed and want it to
                            stay live whatever the requests say.
                        </span>
                    </span>
                </label>
            </div>
        </Modal>
    );
}

/** The release did worse than the one before it and traffic went back. */
export function RolledBackNotice({
    slug,
    deployment,
}: {
    slug: string;
    deployment: DeploymentDetail;
}) {
    const [asking, setAsking] = useState(false);

    return (
        <Alert variant="warning">
            <div className="flex w-full flex-wrap items-center justify-between gap-3">
                <div className="flex items-start gap-3">
                    <Undo2 className="mt-0.5 size-5 shrink-0" aria-hidden />
                    <div>
                        <p className="font-medium">
                            This release was rolled back
                        </p>
                        <p className="text-sm">
                            {deployment.error ??
                                'It did clearly worse than the release before it.'}{' '}
                            All traffic is on the previous release.
                        </p>
                    </div>
                </div>
                {deployment.can_retry && (
                    <Button size="sm" onClick={() => setAsking(true)}>
                        <Play className="size-4" aria-hidden />
                        Go live again
                    </Button>
                )}
            </div>
            <RetryModal
                open={asking}
                onClose={() => setAsking(false)}
                slug={slug}
                number={deployment.number}
            />
        </Alert>
    );
}

/** A release that is live and being watched, with the previous one still running. */
export function WatchPanel({
    slug,
    deployment,
    traffic,
    previous,
}: {
    slug: string;
    deployment: DeploymentDetail;
    traffic: Traffic;
    previous: number | null;
}) {
    const now = useNow();
    const [asking, setAsking] = useState(false);
    const watch = deployment.watch;

    // A rolled back release is explained at the top of the page instead.
    if (!watch || watch.state === 'rolled_back') return null;

    const watching = watch.state === 'watching';
    const left = minutesLeft(watch.until, now);

    const endWatch = () =>
        router.post(
            DeploymentController.endWatch({
                app: slug,
                deployment: deployment.number,
            }).url,
            {},
            { preserveScroll: true, onFinish: () => setAsking(false) },
        );

    return (
        <Card
            title={watching ? 'Watching this release' : 'The watch'}
            description={
                watching
                    ? `Until ${clock(watch.until)} (${left} left). The previous version keeps running, so traffic can go straight back to it.`
                    : 'The watch is over.'
            }
        >
            <div className="space-y-4">
                <div className="flex items-center gap-3">
                    <div
                        className={cn(
                            'flex size-10 shrink-0 items-center justify-center rounded-box',
                            watching
                                ? 'bg-info/10 text-info'
                                : 'bg-success/10 text-success',
                        )}
                    >
                        <ShieldCheck className="size-5" aria-hidden />
                    </div>
                    <p className="text-sm">
                        {watching
                            ? 'If it does clearly worse than the release before it, CommyPanel rolls back by itself.'
                            : (watch.note ?? 'It held up.')}
                    </p>
                </div>
                {watching && traffic && (
                    <Comparison traffic={traffic} previous={previous} />
                )}
                {watching && (
                    <Button
                        variant="soft"
                        size="sm"
                        onClick={() => setAsking(true)}
                    >
                        <SquareX className="size-4" aria-hidden />
                        End the watch now
                    </Button>
                )}
            </div>
            <ConfirmModal
                open={asking}
                title="End the watch now?"
                confirmLabel="End the watch"
                onConfirm={endWatch}
                onClose={() => setAsking(false)}
            >
                The new release is accepted and the previous version is stopped.
                Going back is then a normal rollback, not an instant switch.
            </ConfirmModal>
        </Card>
    );
}

/** The steps of a gradual rollout, with where it is now. */
function Stepper({
    rollout,
}: {
    rollout: NonNullable<SafeDeployInfo['rollout']>;
}) {
    const nodes = [...rollout.steps, 100];
    const finished = rollout.state === 'promoted';
    const failed =
        rollout.state === 'aborted' || rollout.state === 'rolled_back';

    return (
        <ol
            className="flex flex-wrap items-center gap-2"
            aria-label="Rollout steps"
        >
            {nodes.map((percent, index) => {
                const last = index === nodes.length - 1;
                const current =
                    !finished && !failed && index === rollout.step - 1;
                const done = finished || index < rollout.step - 1;
                const stopped = failed && index === rollout.step - 1;

                return (
                    <li key={index} className="flex items-center gap-2">
                        <span
                            className={cn(
                                'inline-flex min-w-16 items-center justify-center gap-1.5 rounded-field border px-3 py-1.5 text-sm font-semibold',
                                done &&
                                    'border-success/40 bg-success/10 text-success',
                                current &&
                                    (rollout.state === 'waiting'
                                        ? 'border-warning/50 bg-warning/10 text-warning'
                                        : 'border-primary bg-primary/10 text-primary'),
                                stopped &&
                                    'border-error/40 bg-error/10 text-error',
                                !done &&
                                    !current &&
                                    !stopped &&
                                    'border-base-300 text-base-content/60',
                            )}
                            aria-current={current ? 'step' : undefined}
                        >
                            {done && (
                                <CheckCircle2 className="size-4" aria-hidden />
                            )}
                            {current &&
                                (rollout.state === 'waiting' ? (
                                    <Pause className="size-4" aria-hidden />
                                ) : (
                                    <Split className="size-4" aria-hidden />
                                ))}
                            {stopped && (
                                <TriangleAlert className="size-4" aria-hidden />
                            )}
                            {percent}%
                        </span>
                        {!last && (
                            <ArrowRight
                                className="size-4 text-base-content/40"
                                aria-hidden
                            />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

/** A gradual rollout: where it is, how the new release compares, and what a person can do. */
export function RolloutPanel({
    slug,
    rollout,
    traffic,
}: {
    slug: string;
    rollout: NonNullable<SafeDeployInfo['rollout']>;
    traffic: Traffic;
}) {
    const now = useNow();
    const [ask, setAsk] = useState<'promote' | 'abort' | null>(null);
    const active = rollout.state === 'running' || rollout.state === 'waiting';
    const post = (action: 'advance' | 'promote' | 'abort') =>
        router.post(
            RolloutController[action](slug).url,
            {},
            { preserveScroll: true, onFinish: () => setAsk(null) },
        );

    const messages: Record<string, ReactNode> = {
        running: `Holding at ${rollout.percent}% until ${clock(rollout.step_ends_at)} (${minutesLeft(rollout.step_ends_at, now)} left). ${rollout.auto ? 'It moves on by itself while the requests look healthy.' : 'Then it waits for you.'}`,
        waiting: rollout.note ?? 'It is waiting for you.',
        promoted: rollout.note ?? 'Everyone has the new release.',
        aborted:
            'The rollout was aborted. All traffic is on the previous release.',
        rolled_back: `${rollout.note ?? 'The release did worse.'} The share was taken away and the release stopped.`,
    };

    return (
        <Card
            title="Gradual rollout"
            description={`Step ${Math.min(rollout.step, rollout.steps.length)} of ${rollout.steps.length}, ${rollout.percent}% of visitors${rollout.state === 'promoted' ? ' (finished)' : ''}`}
        >
            <div className="space-y-5">
                <Stepper rollout={rollout} />
                {rollout.state === 'waiting' ? (
                    <Alert variant="warning">
                        <span className="font-medium">Waiting for you. </span>
                        {messages.waiting}
                    </Alert>
                ) : (
                    <p
                        className={cn(
                            'text-sm',
                            rollout.state === 'rolled_back' && 'text-error',
                            rollout.state === 'promoted' && 'text-success',
                            (rollout.state === 'running' ||
                                rollout.state === 'aborted') &&
                                'text-base-content/70',
                        )}
                    >
                        {messages[rollout.state]}
                    </p>
                )}
                {active && traffic && (
                    <Comparison
                        traffic={traffic}
                        previous={rollout.previous_number}
                    />
                )}
                {active && (
                    <div className="flex flex-wrap gap-2">
                        <Button size="sm" onClick={() => post('advance')}>
                            <ArrowRight className="size-4" aria-hidden />
                            {rollout.step >= rollout.steps.length
                                ? 'Give everyone this release'
                                : `Move on to ${rollout.steps[rollout.step]}%`}
                        </Button>
                        {rollout.step < rollout.steps.length && (
                            <Button
                                variant="soft"
                                size="sm"
                                onClick={() => setAsk('promote')}
                            >
                                Give everyone now
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setAsk('abort')}
                        >
                            <SquareX className="size-4" aria-hidden />
                            Abort
                        </Button>
                    </div>
                )}
            </div>
            <ConfirmModal
                open={ask === 'promote'}
                title="Give everyone this release now?"
                confirmLabel="Give everyone this release"
                onConfirm={() => post('promote')}
                onClose={() => setAsk(null)}
            >
                Skips the steps that are left: all visitors get the new release
                and the previous version is stopped. Going back is then a normal
                rollback.
            </ConfirmModal>
            <ConfirmModal
                open={ask === 'abort'}
                title="Abort the rollout?"
                confirmLabel="Abort"
                onConfirm={() => post('abort')}
                onClose={() => setAsk(null)}
            >
                The new release loses its share of the visitors and is stopped.
                All traffic stays on the previous release. You can deploy again
                whenever you like.
            </ConfirmModal>
        </Card>
    );
}
