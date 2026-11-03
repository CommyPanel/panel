import { Link, router, useForm } from '@inertiajs/react';
import { ExternalLink, Lock, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import WelcomeController from '@/actions/App/Http/Controllers/WelcomeController';
import { Stepper } from '@/components/apps/setup-steps';
import { RecordsTable } from '@/components/domains/domain-card';
import { ServerAddressNote } from '@/components/domains/server-address-card';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/format';
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
    const issuing = panel.certificate.status === 'issuing';

    // Issuing takes a little while; follow it without a manual refresh.
    useEffect(() => {
        if (!issuing) return;
        const timer = setInterval(
            () => router.reload({ only: ['panel'] }),
            2000,
        );
        return () => clearInterval(timer);
    }, [issuing]);

    return (
        <AppLayout title="Welcome">
            <PageHeader
                title="Welcome to CommyPanel"
                description="Two quick steps to serve the panel on your own domain with HTTPS. You can skip both and do them later."
            />
            <Stepper
                index={https.enabled ? 2 : 1}
                steps={[
                    { id: 'admin', label: 'Admin account' },
                    { id: 'https', label: "Let's Encrypt" },
                    { id: 'domain', label: 'Panel domain' },
                ]}
            />
            <div className="space-y-6">
                {!https.enabled ? (
                    <TermsStep https={https} />
                ) : (
                    <DomainStep panel={panel} server={server} />
                )}
                <div className="flex flex-wrap gap-2">
                    <Link
                        href={DashboardController.url()}
                        className="btn btn-primary"
                    >
                        {https.enabled && panel.certificate.status === 'active'
                            ? 'Finish'
                            : 'Skip for now'}
                    </Link>
                </div>
            </div>
        </AppLayout>
    );
}

function TermsStep({ https }: { https: HttpsSettings }) {
    const form = useForm({ agree: false, email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(WelcomeController.terms());
    };

    return (
        <Card
            title="Let's Encrypt"
            description="Free certificates for the panel and your apps, renewed automatically. Let's Encrypt asks you to accept its agreement first."
        >
            {https.staging && (
                <Alert variant="warning">
                    Test mode: certificates come from Let's Encrypt's staging
                    service, so browsers will warn about them.
                </Alert>
            )}
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Contact email (optional)"
                    type="email"
                    placeholder="you@example.com"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                    hint="Sent to Let's Encrypt so they can warn you about problems with your certificates."
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
                        I agree to the{' '}
                        <a
                            href="https://letsencrypt.org/repository/"
                            target="_blank"
                            rel="noreferrer"
                            className="link link-primary"
                        >
                            Let's Encrypt Subscriber Agreement
                        </a>
                        .
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
                    Turn on HTTPS
                </Button>
            </form>
        </Card>
    );
}

function DomainStep({
    panel,
    server,
}: {
    panel: Panel;
    server: ServerAddress;
}) {
    return panel.domain === null ? (
        <ChooseDomain />
    ) : (
        <>
            <ServerAddressNote server={server} />
            <DomainStatus panel={panel} server={server} />
        </>
    );
}

function ChooseDomain() {
    const form = useForm({ domain: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(WelcomeController.domain());
    };

    return (
        <Card
            title="Panel domain"
            description="Serve this panel on a domain of your own, on the normal HTTPS port. Until then it stays reachable on its current address."
        >
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Domain"
                    placeholder="panel.example.com"
                    required
                    value={form.data.domain}
                    onChange={(e) => form.setData('domain', e.target.value)}
                    error={form.errors.domain}
                    hint="A domain or subdomain you control. The next screen shows the DNS records to create."
                />
                <Button type="submit" loading={form.processing}>
                    Continue
                </Button>
            </form>
        </Card>
    );
}

function DomainStatus({
    panel,
    server,
}: {
    panel: Panel;
    server: ServerAddress;
}) {
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
            title={panel.domain ?? 'Panel domain'}
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
                    Use another domain
                </Button>
            }
        >
            {certificate.status === 'active' && panel.url ? (
                <div className="space-y-3">
                    <Alert variant="success">
                        <Lock className="size-4 shrink-0" aria-hidden />
                        <span>
                            The panel is now served at {panel.url}
                            {certificate.expires_at
                                ? ` (certificate valid until ${formatDate(certificate.expires_at)})`
                                : ''}
                            .
                        </span>
                    </Alert>
                    <a
                        href={panel.url}
                        className="btn btn-soft"
                        rel="noreferrer"
                    >
                        <ExternalLink className="size-4" aria-hidden />
                        Open the panel on its domain
                    </a>
                    <p className="text-sm text-base-content/70">
                        You will sign in again there. The old address keeps
                        working as a fallback.
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
                            DNS is correct. The certificate is being requested.
                        </Alert>
                    )}
                    {certificate.status === 'issuing' && (
                        <Alert variant="info">
                            Getting the certificate from Let's Encrypt…
                        </Alert>
                    )}
                    {certificate.status === 'failed' && (
                        <Alert variant="error">
                            {certificate.error ??
                                'The certificate could not be issued.'}
                        </Alert>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm text-base-content/70">
                            {dns?.checked_at
                                ? `Last checked ${formatDate(dns.checked_at)}`
                                : 'Not checked yet'}
                        </p>
                        <Button
                            variant="soft"
                            size="sm"
                            onClick={check}
                            loading={checking}
                        >
                            <RefreshCw className="size-4" aria-hidden />
                            {certificate.status === 'failed'
                                ? 'Check and retry'
                                : 'Check DNS'}
                        </Button>
                    </div>
                </div>
            )}
        </Card>
    );
}
