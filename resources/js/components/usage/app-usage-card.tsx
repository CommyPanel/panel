import AppUsageController from '@/actions/App/Http/Controllers/AppUsageController';
import { TimeSeriesChart } from '@/components/usage/time-series-chart';
import { Alert } from '@/components/ui/alert';
import { Card } from '@/components/ui/card';
import { formatBytes } from '@/lib/format';
import {
    formatPercent,
    formatRate,
    useSeries,
    useUsagePoll,
} from '@/lib/usage';
import type { AppUsage, UsagePoint } from '@/lib/usage';

const COLOR_1 = 'var(--chart-1)';
const COLOR_2 = 'var(--chart-2)';

const pickContainer = (usage: AppUsage) => ({
    history: usage.container?.history ?? [],
    point: usage.container
        ? {
              t: usage.container.t,
              cpu: usage.container.cpu_percent,
              mem: usage.container.mem_used,
              rx: usage.container.net_rx_bps,
              tx: usage.container.net_tx_bps,
          }
        : null,
});

function column<K extends keyof UsagePoint>(series: UsagePoint[], key: K) {
    return series.map((p) => p[key]);
}

/**
 * CPU, memory and network of the app's running container: the last five
 * minutes, then live. Only mounted while the tab is open, so it only polls
 * while somebody is looking.
 */
export function AppUsageCard({
    slug,
    stopped,
}: {
    slug: string;
    stopped: boolean;
}) {
    const { first, latest, error } = useUsagePoll<AppUsage>(
        AppUsageController.show(slug).url,
    );
    const series = useSeries(first, latest, pickContainer);
    const times = column(series, 't');
    const limit = latest?.container?.mem_limit;

    return (
        <Card
            title="Resource usage"
            description="The running container, live. CPU is a share of one core: 100% is one full core."
        >
            {error && (
                <Alert variant="warning">
                    Usage could not be read: {error}
                    {latest && ' Showing the last reading.'}
                </Alert>
            )}
            {stopped && (
                <p className="text-sm text-base-content/70">
                    The app is stopped, so there is nothing to measure.
                </p>
            )}
            <div className="grid gap-6 lg:grid-cols-3">
                <TimeSeriesChart
                    title="CPU"
                    times={times}
                    series={[
                        {
                            label: 'CPU',
                            color: COLOR_1,
                            values: column(series, 'cpu'),
                        },
                    ]}
                    format={formatPercent}
                    yFloor={100}
                />
                <TimeSeriesChart
                    title={
                        limit
                            ? `Memory (limit ${formatBytes(limit)})`
                            : 'Memory'
                    }
                    times={times}
                    series={[
                        {
                            label: 'Memory used',
                            color: COLOR_1,
                            values: column(series, 'mem'),
                        },
                    ]}
                    format={formatBytes}
                    yMax={limit || undefined}
                />
                <TimeSeriesChart
                    title="Network"
                    times={times}
                    series={[
                        {
                            label: 'Received',
                            color: COLOR_1,
                            values: column(series, 'rx'),
                        },
                        {
                            label: 'Sent',
                            color: COLOR_2,
                            values: column(series, 'tx'),
                        },
                    ]}
                    format={formatRate}
                    yFloor={10_000}
                />
            </div>
        </Card>
    );
}
