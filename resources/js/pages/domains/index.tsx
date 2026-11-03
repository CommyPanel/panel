import { Link } from '@inertiajs/react';
import { Globe, Lock, Server } from 'lucide-react';
import { useState } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import WelcomeController from '@/actions/App/Http/Controllers/WelcomeController';
import {
    DomainStatusMark,
    DomainTlsMark,
} from '@/components/domains/domain-marks';
import { HttpsCard } from '@/components/domains/https-card';
import { ServerAddressCard } from '@/components/domains/server-address-card';
import { PageHeader } from '@/components/page-header';
import { Card } from '@/components/ui/card';
import { Tabs, tabPanelProps } from '@/components/ui/tabs';
import type { TabItem } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { formatDate, timeAgo } from '@/lib/format';
import type {
    DomainRow,
    HttpsSettings,
    PanelSummary,
    ServerAddress,
} from '@/types';

type Props = {
    server: ServerAddress;
    https: HttpsSettings;
    panel: PanelSummary;
    domains: DomainRow[];
};

type TabId = 'domains' | 'https' | 'server';

function initialTab(): TabId {
    try {
        const tab = new URLSearchParams(window.location.search).get('tab');

        return tab === 'https' || tab === 'server' ? tab : 'domains';
    } catch {
        return 'domains';
    }
}

function DomainsTable({
    domains,
    panel,
}: {
    domains: DomainRow[];
    panel: PanelSummary;
}) {
    if (domains.length === 0 && panel.domain === null) {
        return (
            <Card
                title="No domains yet"
                description="Open an app and add its domain on its Domains & SSL tab. It will show up here with its DNS status."
            >
                <div>
                    <Link href={AppController.index()} className="btn btn-soft">
                        Go to apps
                    </Link>
                </div>
            </Card>
        );
    }

    return (
        <Card flush>
            <div className="overflow-x-auto">
                <table className="table">
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th>DNS</th>
                            <th>HTTPS</th>
                            <th>App</th>
                            <th>Last checked</th>
                        </tr>
                    </thead>
                    <tbody>
                        {panel.domain && (
                            <tr>
                                <td>
                                    <Link
                                        href={WelcomeController.show()}
                                        className="link font-medium break-all link-primary"
                                    >
                                        {panel.domain}
                                    </Link>
                                </td>
                                <td>-</td>
                                <td>
                                    <DomainTlsMark
                                        tls={
                                            panel.certificate === 'active'
                                                ? 'secured'
                                                : panel.certificate ===
                                                    'issuing'
                                                  ? 'pending'
                                                  : panel.certificate ===
                                                      'failed'
                                                    ? 'failed'
                                                    : 'none'
                                        }
                                    />
                                </td>
                                <td>
                                    <span className="badge-soft badge badge-sm badge-primary">
                                        CommyPanel
                                    </span>
                                </td>
                                <td>-</td>
                            </tr>
                        )}
                        {domains.map((domain) => (
                            <tr
                                key={domain.id}
                                className="hover:bg-base-200/50"
                            >
                                <td className="min-w-48">
                                    <Link
                                        href={DomainController.show(domain.id)}
                                        className="link font-medium break-all link-primary"
                                    >
                                        {domain.name}
                                    </Link>
                                    {domain.redirect_to && (
                                        <p className="text-xs text-base-content/60">
                                            redirects to {domain.redirect_to}
                                        </p>
                                    )}
                                </td>
                                <td>
                                    <DomainStatusMark status={domain.status} />
                                </td>
                                <td>
                                    <DomainTlsMark tls={domain.tls} />
                                </td>
                                <td>
                                    {domain.app ? (
                                        <Link
                                            href={AppController.show(
                                                domain.app,
                                            )}
                                            className="link link-primary"
                                        >
                                            {domain.app_name ?? domain.app}
                                        </Link>
                                    ) : (
                                        <span className="text-base-content/60">
                                            Not assigned
                                        </span>
                                    )}
                                </td>
                                <td
                                    className="whitespace-nowrap"
                                    title={formatDate(domain.last_checked_at)}
                                >
                                    {timeAgo(domain.last_checked_at)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

function PanelCard({ panel }: { panel: PanelSummary }) {
    return (
        <Card
            title="Panel domain"
            description="The address CommyPanel itself is served on."
            actions={
                <Link
                    href={WelcomeController.show()}
                    className="btn btn-soft btn-sm"
                >
                    {panel.domain ? 'Change' : 'Set up'}
                </Link>
            }
        >
            {panel.domain ? (
                <dl className="divide-y divide-base-300 text-sm">
                    <div className="flex justify-between gap-4 py-2">
                        <dt className="text-base-content/70">Domain</dt>
                        <dd className="font-medium break-all">
                            {panel.domain}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-4 py-2">
                        <dt className="text-base-content/70">HTTPS</dt>
                        <dd>
                            <DomainTlsMark
                                tls={
                                    panel.certificate === 'active'
                                        ? 'secured'
                                        : panel.certificate === 'issuing'
                                          ? 'pending'
                                          : panel.certificate === 'failed'
                                            ? 'failed'
                                            : 'none'
                                }
                            />
                        </dd>
                    </div>
                    {panel.expires_at && (
                        <div className="flex justify-between gap-4 py-2">
                            <dt className="text-base-content/70">
                                Valid until
                            </dt>
                            <dd className="font-medium">
                                {formatDate(panel.expires_at)}
                            </dd>
                        </div>
                    )}
                </dl>
            ) : (
                <p className="text-sm text-base-content/70">
                    The panel is reachable on this server's address only. Give
                    it its own domain to serve it on port 443 with a
                    certificate.
                </p>
            )}
        </Card>
    );
}

export default function Index({ server, https, panel, domains }: Props) {
    const [tab, setTab] = useState<TabId>(initialTab);

    const select = (id: TabId) => {
        setTab(id);
        try {
            const url = new URL(window.location.href);
            if (id === 'domains') url.searchParams.delete('tab');
            else url.searchParams.set('tab', id);
            window.history.replaceState(window.history.state, '', url);
        } catch {
            // The tab still switches if the address cannot be updated.
        }
    };

    const tabs: TabItem<TabId>[] = [
        {
            id: 'domains',
            label: 'Domains',
            icon: <Globe className="size-4" aria-hidden />,
            badge: (
                <span className="badge-soft badge-neutral badge badge-sm">
                    {domains.length}
                </span>
            ),
        },
        {
            id: 'https',
            label: 'HTTPS',
            icon: <Lock className="size-4" aria-hidden />,
        },
        {
            id: 'server',
            label: 'Server address',
            icon: <Server className="size-4" aria-hidden />,
        },
    ];

    return (
        <AppLayout title="Domains">
            <PageHeader
                title="Domains"
                description="Every domain on this server. Add a domain from the app it belongs to."
            />

            <Tabs
                tabs={tabs}
                active={tab}
                onChange={select}
                label="Domain sections"
            />

            <div {...tabPanelProps(tab)} className="outline-none">
                {tab === 'domains' && (
                    <DomainsTable domains={domains} panel={panel} />
                )}
                {tab === 'https' && (
                    <div className="grid items-start gap-6 xl:grid-cols-2">
                        <HttpsCard https={https} />
                        <PanelCard panel={panel} />
                    </div>
                )}
                {tab === 'server' && (
                    <div className="grid items-start gap-6 xl:grid-cols-2">
                        <ServerAddressCard server={server} />
                        <Card
                            title="Why this matters"
                            description="Domains are connected by checking that their DNS records point at the addresses saved here."
                        >
                            <p className="text-sm text-base-content/70">
                                CommyPanel finds this server's public address by
                                itself, by reading it from the server's own
                                network connection. Only a server behind a
                                router or load balancer (as on some clouds) is
                                looked up through api.ipify.org instead, and
                                that can be switched off. If the wrong address
                                was found, correct it here or wherever the
                                address is shown.
                            </p>
                        </Card>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
