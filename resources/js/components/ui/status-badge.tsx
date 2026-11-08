import { useT } from '@/hooks/use-t';
import type { AppStatus, DeploymentStatus, DomainStatus } from '@/types';
import { cn } from '@/lib/utils';

const styles: Record<AppStatus | DeploymentStatus | DomainStatus, string> = {
    setup: 'badge-warning',
    new: 'badge-neutral',
    deploying: 'badge-info',
    running: 'badge-success',
    failed: 'badge-error',
    queued: 'badge-neutral',
    building: 'badge-info',
    starting: 'badge-info',
    active: 'badge-success',
    superseded: 'badge-neutral',
    stopped: 'badge-warning',
    rollout: 'badge-info',
    rolled_back: 'badge-warning',
    deactivated: 'badge-neutral',
    pending: 'badge-neutral',
    verified: 'badge-success',
    misconfigured: 'badge-warning',
    proxied: 'badge-info',
};

type Status = AppStatus | DeploymentStatus | DomainStatus;

/** The words for each status; `t` is the component's translate function. */
function statusLabel(status: Status, t: (key: string) => string): string {
    switch (status) {
        case 'setup':
            return t('setting up');
        case 'active':
            return t('live');
        case 'rollout':
            return t('rolling out');
        case 'rolled_back':
            return t('rolled back');
        case 'verified':
            return t('connected');
        case 'misconfigured':
            return t('needs attention');
        case 'new':
            return t('new');
        case 'deploying':
            return t('deploying');
        case 'running':
            return t('running');
        case 'failed':
            return t('failed');
        case 'queued':
            return t('queued');
        case 'building':
            return t('building');
        case 'starting':
            return t('starting');
        case 'superseded':
            return t('superseded');
        case 'stopped':
            return t('stopped');
        case 'deactivated':
            return t('deactivated');
        case 'pending':
            return t('pending');
        case 'proxied':
            return t('proxied');
        default:
            return status;
    }
}

type Props = {
    status: AppStatus | DeploymentStatus | DomainStatus;
    /** Replaces the default wording for this status. */
    label?: string;
    className?: string;
};

export function StatusBadge({ status, label, className }: Props) {
    const { t } = useT();

    return (
        <span
            className={cn(
                'badge-soft badge whitespace-nowrap',
                styles[status],
                className,
            )}
        >
            {label ?? statusLabel(status, t)}
        </span>
    );
}
