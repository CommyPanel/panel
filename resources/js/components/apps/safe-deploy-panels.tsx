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
import { useT } from '@/hooks/use-t';
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

function minutesLeft(
    iso: string | null,
    now: number,
    t: (key: string, params?: Record<string, number>) => string,
): string {
    if (!iso) return '';

    const seconds = Math.max(0, Math.round((Date.parse(iso) - now) / 1000));

    if (seconds < 60) return t(':seconds s', { seconds });

    return t(':minutes min', { minutes: Math.ceil(seconds / 60) });
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
    const { t } = useT();
    const rows: [string, (s: SafeStat) => string][] = [
        [t('Requests'), (s) => fmt(s.requests)],
        [t('Errors (5xx)'), (s) => `${s.error_percent}%`],
        [t('Slowest 5%'), (s) => `${fmt(s.p95_ms)} ms`],
    ];

    return (
        <div className="space-y-3">
            <div className="overflow-x-auto">
                <table className="table table-sm">
                    <thead>
                        <tr>
                            <th />
                            <th className="tracking-normal normal-case">
                                {t('This release')}
                            </th>
                            <th className="tracking-normal normal-case">
                                {previous
                                    ? t('Release #:number', {
                                          number: previous,
                                      })
                                    : t('Before')}
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
    const { t } = useT();
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
            title={t('Go live again with release #:number?', { number })}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button onClick={go} loading={busy}>
                        <Play className="size-4" aria-hidden />
                        {t('Go live again')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4 text-sm">
                <p>
                    {t(
                        'This release did clearly worse than the one before it, so CommyPanel moved the traffic back. If you want to try it again, it is started from the image that is already built (nothing is built) and takes all the traffic like a normal deployment, with no downtime.',
                    )}
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
                            {t(
                                'Watch it again, and roll back by itself if it does worse',
                            )}
                        </span>
                        <span className="block text-base-content/70">
                            {t(
                                'Untick this if you know why it failed and want it to stay live whatever the requests say.',
                            )}
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
    const { t } = useT();
    const [asking, setAsking] = useState(false);

    return (
        <Alert variant="warning">
            <div className="flex w-full flex-wrap items-center justify-between gap-3">
                <div className="flex items-start gap-3">
                    <Undo2 className="mt-0.5 size-5 shrink-0" aria-hidden />
                    <div>
                        <p className="font-medium">
                            {t('This release was rolled back')}
                        </p>
                        <p className="text-sm">
                            {t(
                                ':reason All traffic is on the previous release.',
                                {
                                    reason:
                                        deployment.error ??
                                        t(
                                            'It did clearly worse than the release before it.',
                                        ),
                                },
                            )}
                        </p>
                    </div>
                </div>
                {deployment.can_retry && (
                    <Button size="sm" onClick={() => setAsking(true)}>
                        <Play className="size-4" aria-hidden />
                        {t('Go live again')}
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
    const { t } = useT();
    const now = useNow();
    const [asking, setAsking] = useState(false);
    const watch = deployment.watch;

    // A rolled back release is explained at the top of the page instead.
    if (!watch || watch.state === 'rolled_back') return null;

    const watching = watch.state === 'watching';
    const left = minutesLeft(watch.until, now, t);

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
            title={watching ? t('Watching this release') : t('The watch')}
            description={
                watching
                    ? t(
                          'Until :time (:left left). The previous version keeps running, so traffic can go straight back to it.',
                          { time: clock(watch.until), left },
                      )
                    : t('The watch is over.')
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
                            ? t(
                                  'If it does clearly worse than the release before it, CommyPanel rolls back by itself.',
                              )
                            : (watch.note ?? t('It held up.'))}
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
                        {t('End the watch now')}
                    </Button>
                )}
            </div>
            <ConfirmModal
                open={asking}
                title={t('End the watch now?')}
                confirmLabel={t('End the watch')}
                onConfirm={endWatch}
                onClose={() => setAsking(false)}
            >
                {t(
                    'The new release is accepted and the previous version is stopped. Going back is then a normal rollback, not an instant switch.',
                )}
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
    const { t } = useT();
    const nodes = [...rollout.steps, 100];
    const finished = rollout.state === 'promoted';
    const failed =
        rollout.state === 'aborted' || rollout.state === 'rolled_back';

    return (
        <ol
            className="flex flex-wrap items-center gap-2"
            aria-label={t('Rollout steps')}
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
    const { t } = useT();
    const now = useNow();
    const [ask, setAsk] = useState<'promote' | 'abort' | null>(null);
    const active = rollout.state === 'running' || rollout.state === 'waiting';
    const post = (action: 'advance' | 'promote' | 'abort') =>
        router.post(
            RolloutController[action](slug).url,
            {},
            { preserveScroll: true, onFinish: () => setAsk(null) },
        );

    const stepParams = {
        step: Math.min(rollout.step, rollout.steps.length),
        total: rollout.steps.length,
        percent: rollout.percent,
    };

    const messages: Record<string, ReactNode> = {
        running: rollout.auto
            ? t(
                  'Holding at :percent% until :time (:left left). It moves on by itself while the requests look healthy.',
                  {
                      percent: rollout.percent,
                      time: clock(rollout.step_ends_at),
                      left: minutesLeft(rollout.step_ends_at, now, t),
                  },
              )
            : t(
                  'Holding at :percent% until :time (:left left). Then it waits for you.',
                  {
                      percent: rollout.percent,
                      time: clock(rollout.step_ends_at),
                      left: minutesLeft(rollout.step_ends_at, now, t),
                  },
              ),
        waiting: rollout.note ?? t('It is waiting for you.'),
        promoted: rollout.note ?? t('Everyone has the new release.'),
        aborted: t(
            'The rollout was aborted. All traffic is on the previous release.',
        ),
        rolled_back: t(
            ':reason The share was taken away and the release stopped.',
            { reason: rollout.note ?? t('The release did worse.') },
        ),
    };

    return (
        <Card
            title={t('Gradual rollout')}
            description={
                rollout.state === 'promoted'
                    ? t(
                          'Step :step of :total, :percent% of visitors (finished)',
                          stepParams,
                      )
                    : t(
                          'Step :step of :total, :percent% of visitors',
                          stepParams,
                      )
            }
        >
            <div className="space-y-5">
                <Stepper rollout={rollout} />
                {rollout.state === 'waiting' ? (
                    <Alert variant="warning">
                        <span className="font-medium">
                            {t('Waiting for you.')}{' '}
                        </span>
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
                                ? t('Give everyone this release')
                                : t('Move on to :percent%', {
                                      percent: rollout.steps[rollout.step],
                                  })}
                        </Button>
                        {rollout.step < rollout.steps.length && (
                            <Button
                                variant="soft"
                                size="sm"
                                onClick={() => setAsk('promote')}
                            >
                                {t('Give everyone now')}
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setAsk('abort')}
                        >
                            <SquareX className="size-4" aria-hidden />
                            {t('Abort')}
                        </Button>
                    </div>
                )}
            </div>
            <ConfirmModal
                open={ask === 'promote'}
                title={t('Give everyone this release now?')}
                confirmLabel={t('Give everyone this release')}
                onConfirm={() => post('promote')}
                onClose={() => setAsk(null)}
            >
                {t(
                    'Skips the steps that are left: all visitors get the new release and the previous version is stopped. Going back is then a normal rollback.',
                )}
            </ConfirmModal>
            <ConfirmModal
                open={ask === 'abort'}
                title={t('Abort the rollout?')}
                confirmLabel={t('Abort')}
                onConfirm={() => post('abort')}
                onClose={() => setAsk(null)}
            >
                {t(
                    'The new release loses its share of the visitors and is stopped. All traffic stays on the previous release. You can deploy again whenever you like.',
                )}
            </ConfirmModal>
        </Card>
    );
}
