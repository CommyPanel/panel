import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    ExternalLink,
    Globe,
    HardDrive,
    Link2,
    Layers,
    FileCode,
    Rocket,
    ScrollText,
    ShieldCheck,
    SquareTerminal,
    Settings,
    SlidersHorizontal,
    Split,
    Variable,
} from 'lucide-react';
import { Suspense, lazy, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import DeploymentController from '@/actions/App/Http/Controllers/DeploymentController';
import { AppPower } from '@/components/apps/app-power';
import { QuickInstallCard } from '@/components/apps/quick-install-card';
import { AdvancedTab } from '@/components/apps/tabs/advanced-tab';
import { DeploymentsTab } from '@/components/apps/tabs/deployments-tab';
import { DomainsTab } from '@/components/apps/tabs/domains-tab';
import { VolumesTab } from '@/components/apps/tabs/volumes-tab';
import { LinksTab } from '@/components/apps/tabs/links-tab';
import { LogsTab } from '@/components/apps/tabs/logs-tab';
import { EnvironmentTab } from '@/components/apps/tabs/environment-tab';
import { NginxTab } from '@/components/apps/tabs/nginx-tab';
import { SafeDeploysTab } from '@/components/apps/tabs/safe-deploys-tab';
import { SettingsTab } from '@/components/apps/tabs/settings-tab';
import { UsageTab } from '@/components/apps/tabs/usage-tab';
import { PendingChanges } from '@/components/apps/pending-changes';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { LockIcon } from '@/components/ui/lock-icon';
import { StatusBadge } from '@/components/ui/status-badge';
import { GroupedTabs } from '@/components/ui/grouped-tabs';
import type { GroupedTab, TabEntry } from '@/components/ui/grouped-tabs';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/lib/permissions';
import type {
    AppSettings,
    AppWebhook,
    DeploymentSummary,
    ServerAddress,
} from '@/types';

// The terminal brings its own library, so it is loaded only when somebody opens it.
const TerminalTab = lazy(() =>
    import('@/components/apps/tabs/terminal-tab').then((m) => ({
        default: m.TerminalTab,
    })),
);

type Props = {
    app: AppSettings;
    deployments: DeploymentSummary[];
    env_keys: string[];
    https: { enabled: boolean; staging: boolean };
    server: ServerAddress;
    webhook: AppWebhook | null;
};

type TabId =
    | 'deployments'
    | 'usage'
    | 'safe'
    | 'logs'
    | 'volumes'
    | 'links'
    | 'terminal'
    | 'domains'
    | 'environment'
    | 'nginx'
    | 'settings'
    | 'advanced';

const TAB_IDS: TabId[] = [
    'deployments',
    'usage',
    'safe',
    'logs',
    'volumes',
    'links',
    'terminal',
    'domains',
    'environment',
    'nginx',
    'settings',
    'advanced',
];

function initialTab(): TabId {
    try {
        const tab = new URLSearchParams(window.location.search).get('tab');

        return TAB_IDS.find((id) => id === tab) ?? 'deployments';
    } catch {
        return 'deployments';
    }
}

function Count({ value }: { value: number }) {
    return value > 0 ? (
        <span className="badge-soft badge-neutral badge badge-sm">{value}</span>
    ) : null;
}

export default function Show({
    app,
    deployments,
    env_keys,
    https,
    server,
    webhook,
}: Props) {
    const { t } = useT();
    const { errors } = usePage().props;
    const can = useCan();
    const [deploying, setDeploying] = useState(false);
    const [tab, setTab] = useState<TabId>(initialTab);
    // The nginx editor reports unsaved changes, so switching tabs can warn first.
    const nginxDirty = useRef(false);
    const canNginx = can('apps.nginx');
    const canLogs = can('apps.logs');
    const canVolumes = can('apps.volumes');
    const canTerminal = can('apps.terminal');
    const canLinks = can('apps.links');
    const active: TabId =
        (tab === 'nginx' && !canNginx) ||
        (tab === 'logs' && !canLogs) ||
        (tab === 'volumes' && !canVolumes) ||
        (tab === 'terminal' && !canTerminal) ||
        (tab === 'links' && !canLinks)
            ? 'deployments'
            : tab;

    // Keep the tab in the address so a reload or a shared link lands on it.
    const select = (id: TabId) => {
        if (
            nginxDirty.current &&
            id !== 'nginx' &&
            !window.confirm(t('You have unsaved nginx changes. Discard them?'))
        ) {
            return;
        }

        setTab(id);
        try {
            const url = new URL(window.location.href);
            if (id === 'deployments') url.searchParams.delete('tab');
            else url.searchParams.set('tab', id);
            window.history.replaceState(window.history.state, '', url);
        } catch {
            // The tab still switches if the address cannot be updated.
        }
    };

    // While a deployment runs, keep the status and the release list fresh.
    useEffect(() => {
        if (app.status !== 'deploying') return;

        const timer = setInterval(
            () => router.reload({ only: ['app', 'deployments'] }),
            2000,
        );

        return () => clearInterval(timer);
    }, [app.status]);

    const deploy = () =>
        router.post(
            DeploymentController.store(app.slug),
            {},
            {
                onStart: () => setDeploying(true),
                onFinish: () => setDeploying(false),
            },
        );

    const item = (
        id: TabId,
        label: string,
        icon: ReactNode,
        badge?: ReactNode,
    ): GroupedTab<TabId> => ({ id, label, icon, badge });

    const groups: {
        id: string;
        label: string;
        icon: ReactNode;
        items: (GroupedTab<TabId> | null)[];
    }[] = [
        {
            id: 'runtime',
            label: t('Runtime'),
            icon: <Activity className="size-4" aria-hidden />,
            items: [
                item(
                    'usage',
                    t('Usage'),
                    <Activity className="size-4" aria-hidden />,
                ),
                canLogs
                    ? item(
                          'logs',
                          t('Logs'),
                          <ScrollText className="size-4" aria-hidden />,
                      )
                    : null,
                canTerminal
                    ? item(
                          'terminal',
                          t('Terminal'),
                          <SquareTerminal className="size-4" aria-hidden />,
                      )
                    : null,
            ],
        },
        {
            id: 'networking',
            label: t('Networking'),
            icon: <Globe className="size-4" aria-hidden />,
            items: [
                item(
                    'domains',
                    t('Domains & SSL'),
                    <Globe className="size-4" aria-hidden />,
                    <Count value={app.domains.length} />,
                ),
                canNginx
                    ? item(
                          'nginx',
                          t('Nginx'),
                          <FileCode className="size-4" aria-hidden />,
                      )
                    : null,
                canLinks
                    ? item(
                          'links',
                          t('Links'),
                          <Link2 className="size-4" aria-hidden />,
                      )
                    : null,
            ],
        },
        {
            id: 'configuration',
            label: t('Configuration'),
            icon: <Settings className="size-4" aria-hidden />,
            items: [
                item(
                    'environment',
                    t('Environment'),
                    <Variable className="size-4" aria-hidden />,
                    <Count value={env_keys.length} />,
                ),
                canVolumes
                    ? item(
                          'volumes',
                          t('Volumes'),
                          <HardDrive className="size-4" aria-hidden />,
                          <Count value={app.volumes.length} />,
                      )
                    : null,
                item(
                    'safe',
                    t('Safe deploys'),
                    <ShieldCheck className="size-4" aria-hidden />,
                ),
                item(
                    'settings',
                    t('Settings'),
                    <Settings className="size-4" aria-hidden />,
                ),
            ],
        },
    ];

    const entries: TabEntry<TabId>[] = [
        item(
            'deployments',
            t('Deployments'),
            <Layers className="size-4" aria-hidden />,
        ),
        ...groups.map((group) => ({
            ...group,
            items: group.items.filter(
                (entry): entry is GroupedTab<TabId> => entry !== null,
            ),
        })),
        item(
            'advanced',
            t('Advanced'),
            <SlidersHorizontal className="size-4" aria-hidden />,
        ),
    ];

    const activeLabel =
        entries
            .flatMap((entry) => ('items' in entry ? entry.items : [entry]))
            .find((entry) => entry.id === active)?.label ?? '';

    return (
        <AppLayout title={app.name}>
            <PageHeader
                title={app.name}
                badge={
                    <>
                        <StatusBadge status={app.status} />
                        {app.locked && <LockIcon what="app" />}
                    </>
                }
                description={
                    app.source_type === 'upload'
                        ? t('Uploaded project')
                        : app.source_type === 'template'
                          ? t('Quick install: :name', {
                                name: app.template?.name ?? '',
                            })
                          : (app.git_url ?? undefined)
                }
                actions={
                    <>
                        {app.url && (
                            <a
                                href={app.url}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="btn btn-soft"
                            >
                                <ExternalLink className="size-4" aria-hidden />
                                {t('Visit')}
                            </a>
                        )}
                        <Link
                            href={AppController.index()}
                            className="btn btn-text"
                        >
                            {t('All apps')}
                        </Link>
                        {can('apps.control') && <AppPower app={app} />}
                        {can('apps.deploy') && (
                            <Button
                                onClick={deploy}
                                loading={deploying}
                                disabled={app.status === 'deploying'}
                            >
                                <Rocket className="size-4" aria-hidden />
                                {t('Deploy')}
                            </Button>
                        )}
                    </>
                }
            />

            {errors.deploy && <Alert variant="error">{errors.deploy}</Alert>}
            {errors.state && <Alert variant="error">{errors.state}</Alert>}
            {deployments[0]?.status === 'rollout' && (
                <Alert variant="info">
                    <div className="flex w-full flex-wrap items-center justify-between gap-3">
                        <span className="flex items-center gap-2">
                            <Split className="size-5 shrink-0" aria-hidden />
                            {t(
                                'A gradual rollout of release #:number is under way.',
                                {
                                    number: deployments[0].number,
                                },
                            )}
                        </span>
                        <Link
                            href={DeploymentController.show({
                                app: app.slug,
                                deployment: deployments[0].number,
                            })}
                            className="btn btn-sm btn-primary"
                        >
                            {t('Open the rollout')}
                        </Link>
                    </div>
                </Alert>
            )}
            {app.pending_changes &&
                app.status !== 'deploying' &&
                app.status !== 'stopped' && <PendingChanges slug={app.slug} />}
            {app.status === 'stopped' && (
                <Alert variant="warning">
                    {t(
                        'This app is stopped. Its domains show an error until you start it again.',
                    )}
                </Alert>
            )}

            {app.template && can('apps.credentials') && (
                <QuickInstallCard
                    slug={app.slug}
                    name={app.template.name}
                    image={app.template.release}
                />
            )}

            <GroupedTabs
                entries={entries}
                active={active}
                onChange={select}
                label={t('App sections')}
            />

            <div role="region" aria-label={activeLabel}>
                {active === 'deployments' && (
                    <DeploymentsTab app={app} deployments={deployments} />
                )}
                {active === 'usage' && <UsageTab app={app} />}
                {active === 'safe' && <SafeDeploysTab app={app} />}
                {active === 'logs' && <LogsTab app={app} />}
                {active === 'volumes' && <VolumesTab app={app} />}
                {active === 'links' && <LinksTab app={app} />}
                {active === 'terminal' && (
                    <Suspense
                        fallback={
                            <p className="text-sm text-base-content/70">
                                {t('Loading...')}
                            </p>
                        }
                    >
                        <TerminalTab app={app} />
                    </Suspense>
                )}
                {active === 'domains' && (
                    <DomainsTab app={app} server={server} https={https} />
                )}
                {active === 'nginx' && (
                    <NginxTab
                        slug={app.slug}
                        onDirtyChange={(dirty) => {
                            nginxDirty.current = dirty;
                        }}
                    />
                )}
                {active === 'environment' && (
                    <EnvironmentTab slug={app.slug} keys={env_keys} />
                )}
                {active === 'settings' && (
                    <SettingsTab app={app} webhook={webhook} />
                )}
                {active === 'advanced' && <AdvancedTab app={app} />}
            </div>
        </AppLayout>
    );
}
