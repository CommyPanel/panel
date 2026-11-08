import { Link, router, useForm } from '@inertiajs/react';
import { ExternalLink, Lock, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import WelcomeController from '@/actions/App/Http/Controllers/WelcomeController';
import { RecordsTable } from '@/components/domains/domain-card';
import { ServerAddressNote } from '@/components/domains/server-address-card';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useProgress } from '@/hooks/use-progress';
import { useT } from '@/hooks/use-t';
import SetupLayout from '@/layouts/setup-layout';
import { formatDate } from '@/lib/format';
import { postQuietly } from '@/lib/post-json';
import { Trans } from '@/lib/trans';
import type { HttpsSettings, ServerAddress } from '@/types';

type Panel = {
    domain: string | null;
    dns: {
        status: string;
        problems?: string[];
        checked_at?: string;
    } | null;
    certificate: {
        status: 'none' | 'issuing' | 'active' | 'failed';
        expires_at: string | null;
        error: string | null;
    };
    url: string | null;
};

type Props = { https: HttpsSettings; server: ServerAddress; panel: Panel };

export default function Welcome({ https, server, panel }: Props) {
    const { t } = useT();
    // The page follows DNS and the certificate by itself: it re-checks DNS while that is
    // not right yet, and keeps looking until the certificate is in place or has failed.
    const waitingForDns =
        panel.domain !== null && panel.dns?.status !== 'verified';
    const waitingForCertificate =
        panel.domain !== null &&
        https.enabled &&
        panel.dns?.status === 'verified' &&
        (panel.certificate.status === 'none' ||
            panel.certificate.status === 'issuing');
    const progress = useProgress({
        active: waitingForDns || waitingForCertificate,
        only: ['panel'],
        checks: waitingForDns
            ? () => postQuietly(WelcomeController.check().url, { auto: true })
            : undefined,
    });

    return (
        <SetupLayout
            title={t('Welcome to CommyPanel')}
            description={t(
                'Two quick steps to serve the panel on your own domain with HTTPS. You can skip both and do them later.',
            )}
            step={https.enabled ? 2 : 1}
        >
            {!https.enabled ? (
                <TermsStep https={https} />
            ) : (
                <DomainStep panel={panel} server={server} progress={progress} />
            )}
            <div className="flex flex-wrap justify-end gap-2">
                <Link
                    href={DashboardController.url()}
                    className="btn btn-primary"
                >
                    {https.enabled && panel.certificate.status === 'active'
                        ? t('Finish')
                        : t('Skip for now')}
                </Link>
            </div>
        </SetupLayout>
    );
}

function TermsStep({ https }: { https: HttpsSettings }) {
    const { t } = useT();
    const form = useForm({ agree: false, email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(WelcomeController.terms());
    };

    return (
        <Card
            title="Let's Encrypt"
            description={t(
                "Free certificates for the panel and your apps, renewed automatically. Let's Encrypt asks you to accept its agreement first.",
            )}
        >
            {https.staging && (
                <Alert variant="warning">
                    {t(
                        "Test mode: certificates come from Let's Encrypt's staging service, so browsers will warn about them.",
                    )}
                </Alert>
            )}
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label={t('Contact email (optional)')}
                    type="email"
                    placeholder="you@example.com"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                    hint={t(
                        "Sent to Let's Encrypt so they can warn you about problems with your certificates.",
                    )}
                />
                <label className="flex items-start gap-3 text-sm">
                    <input
                        type="checkbox"
                        className="checkbox mt-0.5 checkbox-primary"
                        checked={form.data.agree}
                        onChange={(e) =>
                            form.setData('agree', e.target.checked)
                        }
                    />
                    <span>
                        <Trans
                            text="I agree to the <link>Let's Encrypt Subscriber Agreement</link>."
                            components={{
                                link: (
                                    <a
                                        href="https://letsencrypt.org/repository/"
                                        target="_blank"
                                        rel="noreferrer"
                                        className="link link-primary"
                                    />
                                ),
                            }}
                        />
                    </span>
                </label>
                {form.errors.agree && (
                    <span className="block text-sm text-error">
                        {form.errors.agree}
                    </span>
                )}
                <Button
                    type="submit"
                    loading={form.processing}
                    disabled={!form.data.agree}
                >
                    <Lock className="size-4" aria-hidden />
                    {t('Turn on HTTPS')}
                </Button>
            </form>
        </Card>
    );
}

function DomainStep({
    panel,
    server,
    progress,
}: {
    panel: Panel;
    server: ServerAddress;
    progress: { waiting: boolean; gaveUp: boolean };
}) {
    return panel.domain === null ? (
        <ChooseDomain />
    ) : (
        <>
            <ServerAddressNote server={server} />
            <DomainStatus panel={panel} server={server} progress={progress} />
        </>
    );
}

function ChooseDomain() {
    const { t } = useT();
    const form = useForm({ domain: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(WelcomeController.domain());
    };

    return (
        <Card
            title={t('Panel domain')}
            description={t(
                'Serve this panel on a domain of your own, on the normal HTTPS port. Until then it stays reachable on its current address.',
            )}
        >
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label={t('Domain')}
                    placeholder="panel.example.com"
                    required
                    value={form.data.domain}
                    onChange={(e) => form.setData('domain', e.target.value)}
                    error={form.errors.domain}
                    hint={t(
                        'A domain or subdomain you control. The next screen shows the DNS records to create.',
                    )}
                />
                <Button type="submit" loading={form.processing}>
                    {t('Continue')}
                </Button>
            </form>
        </Card>
    );
}

function DomainStatus({
    panel,
    server,
    progress,
}: {
    panel: Panel;
    server: ServerAddress;
    progress: { waiting: boolean; gaveUp: boolean };
}) {
    const { t } = useT();
    const [checking, setChecking] = useState(false);
    const { certificate, dns } = panel;
    const verified = dns?.status === 'verified';

    const check = () =>
        router.post(
            WelcomeController.check(),
            {},
            {
                preserveScroll: true,
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
            },
        );

    return (
        <Card
            title={panel.domain ?? t('Panel domain')}
            actions={
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        router.delete(WelcomeController.clear(), {
                            preserveScroll: true,
                        })
                    }
                >
                    {t('Use another domain')}
                </Button>
            }
        >
            {certificate.status === 'active' && panel.url ? (
                <div className="space-y-3">
                    <Alert variant="success">
                        <Lock className="size-4 shrink-0" aria-hidden />
                        <span>
                            {certificate.expires_at
                                ? t(
                                      'The panel is now served at :url (certificate valid until :date).',
                                      {
                                          url: panel.url,
                                          date: formatDate(
                                              certificate.expires_at,
                                          ),
                                      },
                                  )
                                : t('The panel is now served at :url.', {
                                      url: panel.url,
                                  })}
                        </span>
                    </Alert>
                    <a
                        href={panel.url}
                        className="btn btn-soft"
                        rel="noreferrer"
                    >
                        <ExternalLink className="size-4" aria-hidden />
                        {t('Open the panel on its domain')}
                    </a>
                    <p className="text-sm text-base-content/70">
                        {t(
                            'You will sign in again there. The old address keeps working as a fallback.',
                        )}
                    </p>
                </div>
            ) : (
                <div className="space-y-4">
                    {!verified && panel.domain && (
                        <>
                            {dns?.problems && dns.problems.length > 0 && (
                                <Alert variant="warning">
                                    <ul className="space-y-1">
                                        {dns.problems.map((problem) => (
                                            <li key={problem}>{problem}</li>
                                        ))}
                                    </ul>
                                </Alert>
                            )}
                            <RecordsTable
                                domain={panel.domain}
                                server={server}
                            />
                        </>
                    )}
                    {verified && certificate.status === 'none' && (
                        <Alert variant="info">
                            {t(
                                'DNS is correct. The certificate is being requested.',
                            )}
                        </Alert>
                    )}
                    {certificate.status === 'issuing' && (
                        <Alert variant="info">
                            {t("Getting the certificate from Let's Encrypt…")}
                        </Alert>
                    )}
                    {certificate.status === 'failed' && (
                        <Alert variant="error">
                            {certificate.error ??
                                t('The certificate could not be issued.')}
                        </Alert>
                    )}
                    {progress.waiting && (
                        <p
                            className="text-sm text-base-content/70"
                            role="status"
                        >
                            {t(
                                'Checking by itself every 5 seconds. This page updates when DNS is right and again when the certificate is installed; DNS changes can take a few minutes, sometimes longer.',
                            )}
                        </p>
                    )}
                    {progress.gaveUp && (
                        <p
                            className="text-sm text-base-content/70"
                            role="status"
                        >
                            {t(
                                'Stopped checking automatically after half an hour. CommyPanel still re-checks every five minutes; press Check DNS to look again now.',
                            )}
                        </p>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm text-base-content/70">
                            {dns?.checked_at
                                ? t('Last checked :date', {
                                      date: formatDate(dns.checked_at),
                                  })
                                : t('Not checked yet')}
                        </p>
                        <Button
                            variant="soft"
                            size="sm"
                            onClick={check}
                            loading={checking}
                        >
                            <RefreshCw className="size-4" aria-hidden />
                            {certificate.status === 'failed'
                                ? t('Check and retry')
                                : t('Check DNS')}
                        </Button>
                    </div>
                </div>
            )}
        </Card>
    );
}
