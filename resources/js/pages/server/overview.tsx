import { Link } from '@inertiajs/react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import { ServerLayout } from '@/components/server/server-layout';
import { StateMark, toneOfService } from '@/components/server/state-mark';
import { Card } from '@/components/ui/card';
import { LiveUsage } from '@/components/usage/live-usage';
import { useT } from '@/hooks/use-t';
import { formatBytes, formatUptime } from '@/lib/format';
import { Trans } from '@/lib/trans';

type Props = {
    error: string | null;
    host: {
        hostname: string;
        os: string;
        kernel: string;
        arch: string;
        cpus: number;
        uptime_seconds: number;
        load: [number, number, number];
        memory: { total: number; available: number };
        disk: { total: number; free: number };
        services: { name: string; state: string }[];
        agent_version: string;
    } | null;
    volumes: {
        app: string;
        name: string;
        bytes: number;
        files: number;
        partial: boolean;
    }[];
};

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-wrap justify-between gap-x-4 py-2">
            <dt className="text-base-content/70">{label}</dt>
            <dd className="min-w-0 text-right font-medium break-all">
                {value || '-'}
            </dd>
        </div>
    );
}

export default function Overview({ error, host, volumes }: Props) {
    const { t, tn } = useT();

    return (
        <ServerLayout active="overview" error={error}>
            {host && <LiveUsage />}
            {host && (
                <div className="grid items-start gap-6 xl:grid-cols-2">
                    <div className="min-w-0 space-y-6">
                        <Card title={t('This server')}>
                            <dl className="divide-y divide-base-300 text-sm">
                                <Row
                                    label={t('Hostname')}
                                    value={host.hostname}
                                />
                                <Row label={t('System')} value={host.os} />
                                <Row label={t('Kernel')} value={host.kernel} />
                                <Row
                                    label={t('Processor')}
                                    value={tn(
                                        'one::count core (:arch)|other::count cores (:arch)',
                                        host.cpus,
                                        { arch: host.arch },
                                    )}
                                />
                                <Row
                                    label={t('Uptime')}
                                    value={formatUptime(host.uptime_seconds)}
                                />
                                <Row
                                    label={t('Load (1, 5, 15 min)')}
                                    value={host.load
                                        .map((n) => n.toFixed(2))
                                        .join('  ')}
                                />
                                <Row
                                    label={t('CommyPanel agent')}
                                    value={host.agent_version}
                                />
                            </dl>
                        </Card>
                        <Card
                            title={t('Disk')}
                            description={t(
                                "Everything CommyPanel keeps (apps' volumes, database servers, backups, images) shares this disk.",
                            )}
                        >
                            <DiskUsage disk={host.disk} volumes={volumes} />
                        </Card>
                    </div>
                    <Card
                        title={t('Services')}
                        description={t(
                            'The programs CommyPanel needs. A failed one is the first place to look when something stops working.',
                        )}
                    >
                        <ul className="divide-y divide-base-300 rounded-field border border-base-300">
                            {host.services.map((service) => (
                                <li
                                    key={service.name}
                                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                >
                                    <code className="text-sm">
                                        {service.name}
                                    </code>
                                    <StateMark
                                        tone={toneOfService(service.state)}
                                        label={service.state}
                                    />
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            )}
        </ServerLayout>
    );
}

function DiskUsage({
    disk,
    volumes,
}: {
    disk: { total: number; free: number };
    volumes: Props['volumes'];
}) {
    const { t } = useT();
    const used = Math.max(0, disk.total - disk.free);
    const percent = disk.total > 0 ? Math.round((100 * used) / disk.total) : 0;

    return (
        <div className="space-y-4 text-sm">
            <div>
                <div className="mb-1 flex justify-between">
                    <span>
                        {t(':used used of :total', {
                            used: formatBytes(used),
                            total: formatBytes(disk.total),
                        })}
                    </span>
                    <span className="font-medium">{percent}%</span>
                </div>
                <progress
                    className={`progress w-full ${percent >= 95 ? 'progress-error' : percent >= 85 ? 'progress-warning' : 'progress-primary'}`}
                    value={percent}
                    max={100}
                    aria-label={t('Disk used')}
                />
            </div>
            <div>
                <h3 className="mb-1 font-medium">{t('Biggest app volumes')}</h3>
                {volumes.length === 0 ? (
                    <p className="text-base-content/70">
                        {t('No app keeps files in a volume.')}
                    </p>
                ) : (
                    <ul className="divide-y divide-base-300 rounded-field border border-base-300">
                        {volumes.map((volume) => (
                            <li
                                key={`${volume.app}/${volume.name}`}
                                className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                            >
                                <Link
                                    href={AppController.show(volume.app)}
                                    className="link"
                                >
                                    {volume.app} / {volume.name}
                                </Link>
                                <span className="font-medium">
                                    {volume.partial
                                        ? t('more than :size', {
                                              size: formatBytes(volume.bytes),
                                          })
                                        : formatBytes(volume.bytes)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="mt-2 text-base-content/60">
                    <Trans
                        text="Database servers keep their data in <code>/var/lib/commypanel/databases</code> and backups in <code>/var/lib/commypanel/backups</code>; images are listed under Docker."
                        components={{ code: <code /> }}
                    />
                </p>
            </div>
        </div>
    );
}
