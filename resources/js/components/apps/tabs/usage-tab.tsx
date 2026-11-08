import { AppContainersCard } from '@/components/containers/app-containers-card';
import { Card } from '@/components/ui/card';
import { AppTrafficCard } from '@/components/usage/app-traffic-card';
import { AppUsageCard } from '@/components/usage/app-usage-card';
import { useT } from '@/hooks/use-t';
import type { AppSettings } from '@/types';

/**
 * Live CPU, memory and network of the app's container. Only mounted while the
 * tab is open, so nothing is polled while somebody is looking at another tab.
 */
export function UsageTab({ app }: { app: AppSettings }) {
    const { t } = useT();
    const live = app.status === 'running' || app.status === 'stopped';

    return (
        <div className="space-y-6">
            {live ? (
                <AppUsageCard
                    slug={app.slug}
                    stopped={app.status === 'stopped'}
                />
            ) : (
                <Card
                    title={t('Resource usage')}
                    description={t(
                        'Live CPU, memory and network of the running container.',
                    )}
                >
                    <p className="text-sm text-base-content/70">
                        {t(
                            'There is nothing to measure yet. Usage appears here while the app is running.',
                        )}
                    </p>
                </Card>
            )}
            {app.active_release !== null && <AppTrafficCard slug={app.slug} />}
            <AppContainersCard slug={app.slug} />
        </div>
    );
}
