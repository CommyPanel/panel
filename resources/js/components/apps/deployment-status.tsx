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
import type { DeploymentStatus } from '@/types';

const STATES: Record<
    DeploymentStatus,
    { icon: ReactNode; label: string; tone: string }
> = {
    queued: {
        icon: <CircleDashed className="size-5" aria-hidden />,
        label: 'Queued',
        tone: 'text-base-content/60',
    },
    building: {
        icon: <Loader2 className="size-5 animate-spin" aria-hidden />,
        label: 'Building',
        tone: 'text-info',
    },
    starting: {
        icon: <Loader2 className="size-5 animate-spin" aria-hidden />,
        label: 'Starting',
        tone: 'text-info',
    },
    active: {
        icon: <CheckCircle2 className="size-5" aria-hidden />,
        label: 'Live',
        tone: 'text-success',
    },
    superseded: {
        icon: <CheckCircle2 className="size-5" aria-hidden />,
        label: 'Succeeded',
        tone: 'text-base-content/60',
    },
    failed: {
        icon: <XCircle className="size-5" aria-hidden />,
        label: 'Failed',
        tone: 'text-error',
    },
    stopped: {
        icon: <PauseCircle className="size-5" aria-hidden />,
        label: 'Stopped',
        tone: 'text-warning',
    },
    rollout: {
        icon: <Split className="size-5" aria-hidden />,
        label: 'Rolling out',
        tone: 'text-info',
    },
    rolled_back: {
        icon: <Undo2 className="size-5" aria-hidden />,
        label: 'Rolled back',
        tone: 'text-warning',
    },
};

/** A tick, cross, spinner... with a word next to it, so colour is never the only signal. */
export function DeploymentStatusMark({ status }: { status: DeploymentStatus }) {
    const state = STATES[status];

    return (
        <span className={`inline-flex items-center gap-2 ${state.tone}`}>
            {state.icon}
            <span className="text-sm font-medium">{state.label}</span>
        </span>
    );
}
