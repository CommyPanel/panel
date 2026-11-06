import { SafeDeploysCard } from '@/components/apps/safe-deploys-card';
import type { AppSettings } from '@/types';

/** Watching a release after it goes live, and rolling it out gradually. */
export function SafeDeploysTab({ app }: { app: AppSettings }) {
    return (
        <div className="min-w-0 space-y-6">
            <SafeDeploysCard slug={app.slug} settings={app.safe_deploys} />
        </div>
    );
}
