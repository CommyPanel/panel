import { router } from '@inertiajs/react';
import AppAlertsController from '@/actions/App/Http/Controllers/AppAlertsController';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import { useCan } from '@/lib/permissions';
import type { AppSettings } from '@/types';

/** Mutes the alerts about one app. Server-wide alerts are not affected. */
export function AlertsCard({ app }: { app: AppSettings }) {
    const { t } = useT();
    const can = useCan();

    return (
        <Card
            title={t('Alerts')}
            description={t(
                'Alerts about the server itself (disk, backups, the panel certificate) are not affected by this switch.',
            )}
        >
            <label className="flex cursor-pointer items-start gap-3">
                <input
                    type="checkbox"
                    role="switch"
                    className="switch mt-0.5 switch-primary"
                    checked={app.alerts_enabled}
                    disabled={!can('apps.update')}
                    onChange={(e) =>
                        router.put(
                            AppAlertsController.update(app.slug),
                            { enabled: e.target.checked },
                            { preserveScroll: true },
                        )
                    }
                />
                <span className="text-sm font-medium">
                    {t('Send alerts for this app')}
                </span>
            </label>
        </Card>
    );
}
