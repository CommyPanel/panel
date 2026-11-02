import { cn } from '@/lib/utils';
import type { DatabaseStatus } from '@/types';

const STYLES: Record<DatabaseStatus, string> = {
    provisioning: 'badge-info',
    running: 'badge-success',
    stopped: 'badge-neutral',
    failed: 'badge-error',
};

const LABELS: Record<DatabaseStatus, string> = {
    provisioning: 'Starting up',
    running: 'Running',
    stopped: 'Stopped',
    failed: 'Failed',
};

export function DatabaseStatusBadge({ status }: { status: DatabaseStatus }) {
    return (
        <span className={cn('badge-soft badge badge-sm', STYLES[status])}>
            {LABELS[status]}
        </span>
    );
}
