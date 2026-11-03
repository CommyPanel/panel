import { Link, router } from '@inertiajs/react';
import { RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import { RecordsTable } from '@/components/domains/domain-card';
import {
    DomainStatusMark,
    DomainTlsMark,
} from '@/components/domains/domain-marks';
import { ServerAddressNote } from '@/components/domains/server-address-card';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/format';
import type { DomainCertificate, DomainRow, ServerAddress } from '@/types';

type Props = {
    server: ServerAddress;
    domain: DomainRow;
    certificate: DomainCertificate | null;
    redirected_from: string[];
};

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2">
            <dt className="text-base-content/70">{label}</dt>
            <dd className="min-w-0 text-right font-medium break-all">
                {children}
            </dd>
        </div>
    );
}

export default function Show({
    server,
    domain,
    certificate,
    redirected_from,
}: Props) {
    const [checking, setChecking] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [removing, setRemoving] = useState(false);

    const check = () =>
        router.post(
            DomainController.check(domain.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
            },
        );

    const remove = () =>
        router.delete(DomainController.destroy(domain.id), {
            data: { return: 'domains' },
            onStart: () => setRemoving(true),
            onFinish: () => setRemoving(false),
        });

    const found = [...domain.found.a, ...domain.found.aaaa];

    return (
        <AppLayout title={domain.name}>
            <PageHeader
                title={domain.name}
                badge={<DomainStatusMark status={domain.status} />}
                description={
                    domain.app_name ? `Serves ${domain.app_name}` : undefined
                }
                actions={
                    <>
                        <Link
                            href={DomainController.index()}
                            className="btn btn-text"
                        >
                            All domains
                        </Link>
                        <Button
                            variant="soft"
                            onClick={check}
                            loading={checking}
                        >
                            <RefreshCw className="size-4" aria-hidden />
                            Check DNS
                        </Button>
                    </>
                }
            />

            <div className="grid items-start gap-6 xl:grid-cols-3">
                <div className="min-w-0 space-y-6 xl:col-span-2">
                    <Card
                        title="DNS"
                        description={
                            domain.last_checked_at
                                ? `Last checked ${formatDate(domain.last_checked_at)}`
                                : 'Not checked yet'
                        }
                    >
                        {domain.problems.length > 0 && (
                            <Alert
                                variant={
                                    domain.status === 'proxied'
                                        ? 'info'
                                        : 'warning'
                                }
                            >
                                <ul className="space-y-1">
                                    {domain.problems.map((problem) => (
                                        <li key={problem}>{problem}</li>
                                    ))}
                                </ul>
                            </Alert>
                        )}
                        {domain.status === 'verified' && (
                            <Alert variant="success">
                                DNS points at this server.
                            </Alert>
                        )}

                        <div className="space-y-2">
                            <p className="text-sm font-medium">What we found</p>
                            {found.length > 0 ? (
                                <ul className="flex flex-wrap gap-2">
                                    {found.map((ip) => (
                                        <li key={ip}>
                                            <code className="badge-soft badge-neutral badge">
                                                {ip}
                                            </code>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm text-base-content/70">
                                    No A or AAAA records were found.
                                </p>
                            )}
                        </div>

                        <RecordsTable domain={domain.name} server={server} />
                        <ServerAddressNote server={server} />
                    </Card>
                </div>

                <div className="min-w-0 space-y-6">
                    <Card title="Details">
                        <dl className="divide-y divide-base-300 text-sm">
                            <Row label="App">
                                {domain.app ? (
                                    <Link
                                        href={AppController.show(domain.app)}
                                        className="link link-primary"
                                    >
                                        {domain.app_name ?? domain.app}
                                    </Link>
                                ) : (
                                    'Not assigned'
                                )}
                            </Row>
                            <Row label="Redirect">
                                {domain.redirect_to ? (
                                    <>to {domain.redirect_to}</>
                                ) : redirected_from.length > 0 ? (
                                    <>from {redirected_from.join(', ')}</>
                                ) : (
                                    'None'
                                )}
                            </Row>
                            <Row label="HTTPS">
                                <DomainTlsMark tls={domain.tls} />
                            </Row>
                        </dl>
                    </Card>

                    {certificate && (
                        <Card title="Certificate">
                            {!certificate.covers_domain && (
                                <Alert variant="info">
                                    This domain is not on the app's certificate
                                    yet. It is added once its DNS is connected.
                                </Alert>
                            )}
                            {certificate.staging && (
                                <Alert variant="warning">
                                    Test certificate from Let's Encrypt staging:
                                    browsers will show a warning.
                                </Alert>
                            )}
                            {certificate.last_error && (
                                <Alert variant="error">
                                    {certificate.last_error}
                                </Alert>
                            )}
                            <dl className="divide-y divide-base-300 text-sm">
                                <Row label="Issued">
                                    {formatDate(certificate.issued_at)}
                                </Row>
                                <Row label="Expires">
                                    {formatDate(certificate.expires_at)}
                                </Row>
                                <Row label="Covers">
                                    {certificate.domains.join(', ')}
                                </Row>
                            </dl>
                        </Card>
                    )}

                    <Card
                        title="Remove domain"
                        description="The domain stops serving its app. Your DNS records are not touched."
                    >
                        <div>
                            <Button
                                variant="error"
                                onClick={() => setConfirm(true)}
                            >
                                <Trash2 className="size-4" aria-hidden />
                                Remove domain
                            </Button>
                        </div>
                    </Card>
                </div>
            </div>

            <ConfirmModal
                open={confirm}
                title={`Remove ${domain.name}?`}
                confirmLabel="Remove domain"
                loading={removing}
                onConfirm={remove}
                onClose={() => setConfirm(false)}
            >
                The domain stops serving its app. Your DNS records are not
                touched.
            </ConfirmModal>
        </AppLayout>
    );
}
