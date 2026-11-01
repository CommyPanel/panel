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
    deactivated: 'badge-neutral',
    pending: 'badge-neutral',
    verified: 'badge-success',
    misconfigured: 'badge-warning',
    proxied: 'badge-info',
};

const labels: Partial<
    Record<AppStatus | DeploymentStatus | DomainStatus, string>
> = {
    setup: 'setting up',
    active: 'live',
    verified: 'connected',
    misconfigured: 'needs attention',
};

type Props = {
    status: AppStatus | DeploymentStatus | DomainStatus;
    /** Replaces the default wording for this status. */
    label?: string;
    className?: string;
};

export function StatusBadge({ status, label, className }: Props) {
    return (
        <span
            className={cn(
                'badge-soft badge whitespace-nowrap',
                styles[status],
                className,
            )}
        >
            {label ?? labels[status] ?? status}
        </span>
    );
}
