import {
    CheckCircle2,
    CircleDashed,
    Loader2,
    PauseCircle,
    Split,
    Undo2,
    XCircle,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '@/hooks/use-t';
import type { DeploymentStatus } from '@/types';

const states = (
    t: (key: string) => string,
): Record<
    DeploymentStatus,
    { icon: ReactNode; label: string; tone: string }
> => ({
    queued: {
        icon: <CircleDashed className="size-5" aria-hidden />,
        label: t('Queued'),
        tone: 'text-base-content/60',
    },
    building: {
        icon: <Loader2 className="size-5 animate-spin" aria-hidden />,
        label: t('Building'),
        tone: 'text-info',
    },
    starting: {
        icon: <Loader2 className="size-5 animate-spin" aria-hidden />,
        label: t('Starting'),
        tone: 'text-info',
    },
    active: {
        icon: <CheckCircle2 className="size-5" aria-hidden />,
        label: t('Live'),
        tone: 'text-success',
    },
    superseded: {
        icon: <CheckCircle2 className="size-5" aria-hidden />,
        label: t('Succeeded'),
        tone: 'text-base-content/60',
    },
    failed: {
        icon: <XCircle className="size-5" aria-hidden />,
        label: t('Failed'),
        tone: 'text-error',
    },
    stopped: {
        icon: <PauseCircle className="size-5" aria-hidden />,
        label: t('Stopped'),
        tone: 'text-warning',
    },
    rollout: {
        icon: <Split className="size-5" aria-hidden />,
        label: t('Rolling out'),
        tone: 'text-info',
    },
    rolled_back: {
        icon: <Undo2 className="size-5" aria-hidden />,
        label: t('Rolled back'),
        tone: 'text-warning',
    },
});

/** A tick, cross, spinner... with a word next to it, so colour is never the only signal. */
export function DeploymentStatusMark({ status }: { status: DeploymentStatus }) {
    const { t } = useT();
    const state = states(t)[status];

    return (
        <span className={`inline-flex items-center gap-2 ${state.tone}`}>
            {state.icon}
            <span className="text-sm font-medium">{state.label}</span>
        </span>
    );
}
