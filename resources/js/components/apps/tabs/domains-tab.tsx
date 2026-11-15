import { AppDomains } from '@/components/apps/app-domains';
import { CertificateCard } from '@/components/apps/certificate-card';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import type { AppSettings, ServerAddress } from '@/types';

type Props = {
    app: AppSettings;
    server: ServerAddress;
    https: { enabled: boolean; staging: boolean };
};

export function DomainsTab({ app, server, https }: Props) {
    const { t } = useT();

    // Redis and the like have no web interface: other apps reach them through a link.
    if (app.template?.private) {
        return (
            <Card title={t('No web address')}>
                <p className="text-sm text-base-content/70">
                    {t(
                        'This app has no web interface, so it takes no domain. Other apps reach it through a link: add one on their Links tab.',
                    )}
                </p>
            </Card>
        );
    }

    return (
        <div className="grid items-start gap-6 xl:grid-cols-3">
            <div className="min-w-0 xl:col-span-2">
                <AppDomains
                    slug={app.slug}
                    domains={app.domains}
                    server={server}
                />
            </div>
            <div className="min-w-0">
                <CertificateCard
                    slug={app.slug}
                    certificate={app.certificate}
                    httpsEnabled={https.enabled}
                    hasDomains={app.domains.length > 0}
                />
            </div>
        </div>
    );
}
