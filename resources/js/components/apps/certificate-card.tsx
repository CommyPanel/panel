import { Link, router, usePage } from '@inertiajs/react';
import { RefreshCw, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import CertificateController from '@/actions/App/Http/Controllers/CertificateController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CommandBox } from '@/components/ui/command-box';
import { StatusBadge } from '@/components/ui/status-badge';
import { useT } from '@/hooks/use-t';
import { formatDate } from '@/lib/format';
import { Trans } from '@/lib/trans';
import type { CertificateSummary } from '@/types';

type Props = {
    slug: string;
    certificate: CertificateSummary | null;
    httpsEnabled: boolean;
    hasDomains: boolean;
    /** Whether a Cloudflare token is connected, so wildcard names are proven automatically. */
    cloudflare?: boolean;
    /** Whether the app has a wildcard domain. */
    hasWildcard?: boolean;
};

/** The TXT records a wildcard certificate waits for, each name and value with a copy button. */
function TxtRecords({
    records,
}: {
    records: { name: string; value: string }[];
}) {
    const { t } = useT();

    return (
        <div className="space-y-3">
            {records.map((record) => (
                <div key={record.name + record.value} className="space-y-1">
                    <p className="text-xs text-base-content/70">
                        {t('Name (type TXT)')}
                    </p>
                    <CommandBox command={record.name} />
                    <p className="text-xs text-base-content/70">{t('Value')}</p>
                    <CommandBox command={record.value} />
                </div>
            ))}
        </div>
    );
}

function Details({ certificate }: { certificate: CertificateSummary }) {
    const { t } = useT();

    return (
        <dl className="divide-y divide-base-300 text-sm">
            <div className="flex justify-between gap-4 py-2">
                <dt className="text-base-content/70">{t('Issued')}</dt>
                <dd className="font-medium">
                    {formatDate(certificate.issued_at)}
                </dd>
            </div>
            <div className="flex justify-between gap-4 py-2">
                <dt className="text-base-content/70">{t('Expires')}</dt>
                <dd className="font-medium">
                    {formatDate(certificate.expires_at)}
                </dd>
            </div>
            <div className="flex justify-between gap-4 py-2">
                <dt className="text-base-content/70">{t('Covers')}</dt>
                <dd className="text-right font-medium break-all">
                    {certificate.domains.join(', ')}
                </dd>
            </div>
        </dl>
    );
}

export function CertificateCard({
    slug,
    certificate,
    httpsEnabled,
    hasDomains,
    cloudflare = false,
    hasWildcard = false,
}: Props) {
    const { t } = useT();
    const { errors } = usePage().props;
    const [busy, setBusy] = useState(false);

    const request = () =>
        router.post(
            CertificateController.store(slug),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    const refreshDns = () =>
        router.post(
            CertificateController.refreshDns(slug),
            {},
            { preserveScroll: true },
        );

    const badges = {
        issuing: { status: 'building', label: t('requesting') },
        awaiting_dns: { status: 'pending', label: t('waiting for DNS') },
        active: { status: 'active', label: t('valid') },
        failed: { status: 'failed', label: t('failed') },
    } as const;
    const badge = certificate ? badges[certificate.status] : null;
    const canRequest = httpsEnabled && hasDomains;
    const hasIssued = certificate?.expires_at != null;

    return (
        <Card
            title={t('HTTPS certificate')}
            actions={
                badge && (
                    <StatusBadge status={badge.status} label={badge.label} />
                )
            }
        >
            {errors.certificate && (
                <Alert variant="error">{errors.certificate}</Alert>
            )}

            {!httpsEnabled && (
                <Alert variant="warning">
                    <span>
                        {certificate ? (
                            <Trans
                                text="Automatic HTTPS is off, so this certificate will not be renewed. <link>Turn it on in Domains</link>."
                                components={{
                                    link: (
                                        <Link
                                            href={DomainController.index()}
                                            className="link font-medium"
                                        />
                                    ),
                                }}
                            />
                        ) : (
                            <Trans
                                text="Automatic HTTPS is off. <link>Turn it on in Domains</link>."
                                components={{
                                    link: (
                                        <Link
                                            href={DomainController.index()}
                                            className="link font-medium"
                                        />
                                    ),
                                }}
                            />
                        )}
                    </span>
                </Alert>
            )}

            {certificate?.staging && (
                <Alert variant="warning">
                    {t(
                        "Test certificate from Let's Encrypt staging: browsers will show a warning.",
                    )}
                </Alert>
            )}

            {certificate?.last_error && (
                <Alert
                    variant={
                        certificate.status === 'failed' ? 'error' : 'warning'
                    }
                >
                    {certificate.last_error}
                </Alert>
            )}

            {certificate?.dns_pending && (
                <Alert variant="info">
                    <div className="w-full space-y-3">
                        <p className="font-medium">
                            {certificate.dns_pending.renewal
                                ? t(
                                      'The wildcard certificate needs a new TXT record to be renewed.',
                                  )
                                : t(
                                      'Create this TXT record at your DNS provider to get the wildcard certificate.',
                                  )}
                        </p>
                        <TxtRecords records={certificate.dns_pending.records} />
                        <p className="text-sm">
                            {t(
                                'CommyPanel checks every few minutes and finishes the certificate as soon as it sees the record. If two records share a name, create both. Keep the old ones; they do no harm.',
                            )}
                        </p>
                        {certificate.dns_pending.renewal && (
                            <p className="text-sm">
                                {t(
                                    'Without Cloudflare connected, each renewal (every 60 days or so) needs a new record like this one. The current certificate keeps working until it ends.',
                                )}
                            </p>
                        )}
                        <Button variant="soft" size="sm" onClick={refreshDns}>
                            <RefreshCw className="size-4" aria-hidden />
                            {t('Get new records')}
                        </Button>
                    </div>
                </Alert>
            )}

            {hasWildcard && !cloudflare && !certificate?.dns_pending && (
                <p className="text-sm text-base-content/70">
                    <Trans
                        text="A wildcard certificate is checked through DNS. <link>Connect Cloudflare in Domains</link> to make and renew it automatically, or create the TXT record you are shown by hand."
                        components={{
                            link: (
                                <Link
                                    href={DomainController.index()}
                                    className="link font-medium"
                                />
                            ),
                        }}
                    />
                </p>
            )}

            {certificate && hasIssued && <Details certificate={certificate} />}

            {!certificate && httpsEnabled && !hasDomains && (
                <p className="text-sm text-base-content/70">
                    {t(
                        'Connect a domain to this app first. A certificate is requested automatically once its DNS points at this server.',
                    )}
                </p>
            )}

            {canRequest && (
                <div>
                    <Button
                        variant="soft"
                        onClick={request}
                        loading={busy}
                        disabled={certificate?.status === 'issuing'}
                    >
                        <ShieldCheck className="size-4" aria-hidden />
                        {certificate?.dns_pending
                            ? t('Check now')
                            : hasIssued
                              ? t('Renew now')
                              : t('Get certificate')}
                    </Button>
                </div>
            )}
        </Card>
    );
}
