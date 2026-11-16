import { SafeDeploysCard } from '@/components/apps/safe-deploys-card';
import { Alert } from '@/components/ui/alert';
import { useT } from '@/hooks/use-t';
import type { AppSettings } from '@/types';

/** Watching a release after it goes live, and rolling it out gradually. */
export function SafeDeploysTab({ app }: { app: AppSettings }) {
    const { t } = useT();

    return (
        <div className="min-w-0 space-y-6">
            {app.deploy_strategy === 'stop_first' && (
                <Alert variant="info">
                    {t(
                        'This app stops the old version before the new one starts, so there is no old version to watch or to roll out beside it. These settings do not apply until you switch the deploy mode back to Zero downtime.',
                    )}
                </Alert>
            )}
            <SafeDeploysCard slug={app.slug} settings={app.safe_deploys} />
        </div>
    );
}
