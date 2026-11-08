import { CheckCircle2, Circle, Loader2, XCircle } from 'lucide-react';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';
import type { DeploymentStage, DeploymentStatus } from '@/types';

type Step = { stage: DeploymentStage; label: string; detail: string };

const steps = (t: (key: string) => string): Step[] => [
    {
        stage: 'fetch',
        label: t('Get the code'),
        detail: t('Clone the repository or unpack the upload'),
    },
    {
        stage: 'build',
        label: t('Build the image'),
        detail: t('Detect the project and build a container image'),
    },
    {
        stage: 'release',
        label: t('Release command'),
        detail: t('Run the release command in the new image'),
    },
    {
        stage: 'start',
        label: t('Start the container'),
        detail: t('Run the new version next to the old one'),
    },
    {
        stage: 'health',
        label: t('Health check'),
        detail: t('Wait until the app answers'),
    },
    {
        stage: 'switch',
        label: t('Go live'),
        detail: t('Move traffic to the new version'),
    },
];

type State = 'done' | 'active' | 'failed' | 'pending';

function stateOf(
    index: number,
    current: number,
    status: DeploymentStatus,
): State {
    if (
        status === 'active' ||
        status === 'superseded' ||
        status === 'stopped' ||
        status === 'rollout' ||
        status === 'rolled_back'
    ) {
        return 'done';
    }
    if (current < 0) return 'pending';
    if (index < current) return 'done';
    if (index === current) return status === 'failed' ? 'failed' : 'active';
    return 'pending';
}

const ICONS = {
    done: <CheckCircle2 className="size-5 text-success" aria-hidden />,
    active: (
        <Loader2 className="size-5 animate-spin text-primary" aria-hidden />
    ),
    failed: <XCircle className="size-5 text-error" aria-hidden />,
    pending: <Circle className="size-5 text-base-content/40" aria-hidden />,
};

const stateLabels = (t: (key: string) => string): Record<State, string> => ({
    done: t('done'),
    active: t('in progress'),
    failed: t('failed'),
    pending: t('waiting'),
});

type Props = {
    stage: DeploymentStage | null;
    status: DeploymentStatus;
    /** A rollback reuses an existing image, so it skips fetching, building and the release command. */
    isRollback: boolean;
    hasReleaseCommand: boolean;
};

export function DeploymentStages({
    stage,
    status,
    isRollback,
    hasReleaseCommand,
}: Props) {
    const { t } = useT();
    const labels = stateLabels(t);
    const list = steps(t).filter((s) => {
        if (s.stage === 'release') return hasReleaseCommand && !isRollback;
        if (s.stage === 'fetch' || s.stage === 'build') return !isRollback;
        return true;
    });
    const current =
        stage === 'done'
            ? list.length
            : list.findIndex((s) => s.stage === stage);

    return (
        <ol className="space-y-1" aria-label={t('Deployment progress')}>
            {list.map((step, index) => {
                const state = stateOf(index, current, status);

                return (
                    <li
                        key={step.stage}
                        className={cn(
                            'flex items-start gap-3 rounded-field px-3 py-2',
                            state === 'active' && 'bg-primary/5',
                            state === 'failed' && 'bg-error/5',
                        )}
                    >
                        <span className="mt-0.5 shrink-0">{ICONS[state]}</span>
                        <span className="min-w-0">
                            <span
                                className={cn(
                                    'block text-sm font-medium',
                                    state === 'pending' &&
                                        'text-base-content/60',
                                )}
                            >
                                {step.label}
                                <span className="sr-only">
                                    {' '}
                                    ({labels[state]})
                                </span>
                            </span>
                            <span className="block text-xs text-base-content/70">
                                {step.detail}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
