import { Link } from '@inertiajs/react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import ServerUsageController from '@/actions/App/Http/Controllers/ServerUsageController';
import { TimeSeriesChart } from '@/components/usage/time-series-chart';
import { UsageBar } from '@/components/usage/usage-bar';
import { Alert } from '@/components/ui/alert';
import { Card } from '@/components/ui/card';
import { formatBytes } from '@/lib/format';
import {
    formatPercent,
    formatRate,
    useSeries,
    useUsagePoll,
} from '@/lib/usage';
import type { ServerUsage, UsagePoint } from '@/lib/usage';

const CPU = 'var(--chart-1)';
const DOWN = 'var(--chart-1)';
const UP = 'var(--chart-2)';

const pickHost = (usage: ServerUsage) => ({
    history: usage.host_history,
    point: usage.host.ready
        ? {
              t: usage.now,
              cpu: usage.host.cpu_percent,
              mem: usage.host.mem_used,
              rx: usage.host.net_rx_bps,
              tx: usage.host.net_tx_bps,
          }
        : null,
});

function Tile({
    label,
    value,
    detail,
    percent,
}: {
    label: string;
    value: string;
    detail?: string;
    /** Shows a bar underneath. */
    percent?: number;
}) {
    return (
        <div className="min-w-0 space-y-1 rounded-box border border-base-300 px-4 py-3">
            <dt className="text-xs text-base-content/70">{label}</dt>
            <dd className="truncate text-lg font-semibold">{value}</dd>
            {detail && (
                <dd className="truncate text-xs text-base-content/70">
                    {detail}
                </dd>
            )}
            {percent !== undefined && (
                <UsageBar percent={percent} label={`${label} used`} />
            )}
        </div>
    );
}

function column<K extends keyof UsagePoint>(series: UsagePoint[], key: K) {
    return series.map((p) => p[key]);
}

/**
 * CPU, memory, disk and network of the whole server, live, with the last five
 * minutes, and what each container is using.
 */
export function LiveUsage() {
    const { first, latest, error } = useUsagePoll<ServerUsage>(
        ServerUsageController.show().url,
    );
    const host = useSeries(first, latest, pickHost);
    const now = latest?.host;
    const ready = now?.ready === true;
    const times = column(host, 't');
    const containers = [...(latest?.containers ?? [])].sort(
        (a, b) => b.cpu_percent - a.cpu_percent,
    );

    return (
        <div className="space-y-6">
            <Card
                title="Live usage"
                description="Updated every two seconds while this page is open."
            >
                {error && (
                    <Alert variant="warning">
                        Usage could not be read: {error}
                        {latest && ' Showing the last reading.'}
                    </Alert>
                )}

                <dl className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <Tile
                        label="CPU"
                        value={ready ? formatPercent(now.cpu_percent) : '-'}
                        detail={
                            now && now.cpus > 0
                                ? `${now.cpus} ${now.cpus === 1 ? 'core' : 'cores'}`
                                : undefined
                        }
                        percent={ready ? now.cpu_percent : undefined}
                    />
                    <Tile
                        label="Memory"
                        value={now ? formatBytes(now.mem_used) : '-'}
                        detail={
                            now ? `of ${formatBytes(now.mem_total)}` : undefined
                        }
                        percent={
                            now && now.mem_total > 0
                                ? (now.mem_used / now.mem_total) * 100
                                : undefined
                        }
                    />
                    <Tile
                        label="Disk"
                        value={now ? formatBytes(now.disk_used) : '-'}
                        detail={
                            now
                                ? `of ${formatBytes(now.disk_total)}`
                                : undefined
                        }
                        percent={
                            now && now.disk_total > 0
                                ? (now.disk_used / now.disk_total) * 100
                                : undefined
                        }
                    />
                    <Tile
                        label="Network"
                        value={ready ? `↓ ${formatRate(now.net_rx_bps)}` : '-'}
                        detail={
                            ready
                                ? `↑ ${formatRate(now.net_tx_bps)}`
                                : undefined
                        }
                    />
                </dl>

                <div className="grid gap-6 lg:grid-cols-3">
                    <TimeSeriesChart
                        title="CPU"
                        times={times}
                        series={[
                            {
                                label: 'CPU',
                                color: CPU,
                                values: column(host, 'cpu'),
                            },
                        ]}
                        format={formatPercent}
                        yMax={100}
                    />
                    <TimeSeriesChart
                        title="Memory"
                        times={times}
                        series={[
                            {
                                label: 'Memory used',
                                color: CPU,
                                values: column(host, 'mem'),
                            },
                        ]}
                        format={formatBytes}
                        yMax={now?.mem_total || undefined}
                    />
                    <TimeSeriesChart
                        title="Network"
                        times={times}
                        series={[
                            {
                                label: 'Received',
                                color: DOWN,
                                values: column(host, 'rx'),
                            },
                            {
                                label: 'Sent',
                                color: UP,
                                values: column(host, 'tx'),
                            },
                        ]}
                        format={formatRate}
                        yFloor={10_000}
                    />
                </div>
            </Card>

            <Card
                title="Containers"
                description="CPU is a share of one core: 100% is one full core. Memory is without reclaimable cache."
                flush
            >
                {containers.length === 0 ? (
                    <p className="p-5 text-sm text-base-content/70 sm:p-6">
                        {latest
                            ? 'No container is running.'
                            : 'Waiting for the first readings.'}
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Container</th>
                                    <th>App</th>
                                    <th className="text-right">CPU</th>
                                    <th>Memory</th>
                                    <th className="text-right">Network</th>
                                </tr>
                            </thead>
                            <tbody>
                                {containers.map((c) => {
                                    const percent =
                                        c.mem_limit > 0
                                            ? (c.mem_used / c.mem_limit) * 100
                                            : 0;

                                    return (
                                        <tr key={c.name}>
                                            <td>
                                                <code className="text-sm">
                                                    {c.name}
                                                </code>
                                            </td>
                                            <td>
                                                {c.app ? (
                                                    <Link
                                                        href={AppController.show(
                                                            c.app.slug,
                                                        )}
                                                        className="link link-primary"
                                                    >
                                                        {c.app.name}
                                                    </Link>
                                                ) : (
                                                    <span className="text-base-content/70">
                                                        -
                                                    </span>
                                                )}
                                                {c.release !== null && (
                                                    <span className="text-base-content/70">
                                                        {' '}
                                                        #{c.release}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="text-right whitespace-nowrap">
                                                {formatPercent(c.cpu_percent)}
                                            </td>
                                            <td className="min-w-40">
                                                <div className="flex items-center gap-2">
                                                    <UsageBar
                                                        percent={percent}
                                                        label={`${c.name} memory used`}
                                                        className="w-20"
                                                    />
                                                    <span className="text-sm whitespace-nowrap">
                                                        {formatBytes(
                                                            c.mem_used,
                                                        )}
                                                        <span className="text-base-content/70">
                                                            {' '}
                                                            /{' '}
                                                            {formatBytes(
                                                                c.mem_limit,
                                                            )}
                                                        </span>
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="text-right text-sm whitespace-nowrap">
                                                ↓ {formatRate(c.net_rx_bps)}
                                                <span className="text-base-content/70">
                                                    {' '}
                                                    ↑ {formatRate(c.net_tx_bps)}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </div>
    );
}
