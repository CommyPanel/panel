import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';
import type { DatabaseStatus } from '@/types';

const STYLES: Record<DatabaseStatus, string> = {
    provisioning: 'badge-info',
    running: 'badge-success',
    stopped: 'badge-neutral',
    failed: 'badge-error',
};

export function DatabaseStatusBadge({ status }: { status: DatabaseStatus }) {
    const { t } = useT();
    const LABELS: Record<DatabaseStatus, string> = {
        provisioning: t('Starting up'),
        running: t('Running'),
        stopped: t('Stopped'),
        failed: t('Failed'),
    };

    return (
        <span className={cn('badge-soft badge badge-sm', STYLES[status])}>
            {LABELS[status]}
        </span>
    );
}
