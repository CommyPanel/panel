import { Activity } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppTrafficController from '@/actions/App/Http/Controllers/AppTrafficController';
import { Card } from '@/components/ui/card';
import { TimeSeriesChart } from '@/components/usage/time-series-chart';
import { useT } from '@/hooks/use-t';

type Release = {
    number: number;
    live: boolean;
    requests: number;
    errors: number;
    error_percent: number;
    p95_ms: number;
    series: { t: number; requests: number; errors: number }[];
};

type Traffic = { from: number; to: number; step: number; releases: Release[] };

const POLL_MS = 10_000;
const COLORS = ['var(--chart-1)', 'var(--chart-2)'];

/**
 * How the app's releases have been doing over the last half hour, from the requests nginx
 * proxied to them: how many, how many failed, and how slow the slowest 5% were. During a
 * rollout or a watch the two releases sit side by side. Only mounted while the tab is open.
 */
export function AppTrafficCard({ slug }: { slug: string }) {
    const { t } = useT();
    const [traffic, setTraffic] = useState<Traffic | null>(null);

    useEffect(() => {
        let timer: ReturnType<typeof setTimeout> | undefined;
        const controller = new AbortController();

        async function load() {
            if (controller.signal.aborted) return;

            if (!document.hidden) {
                try {
                    const response = await fetch(
                        AppTrafficController.show(slug).url,
                        {
                            headers: { Accept: 'application/json' },
                            signal: controller.signal,
                        },
                    );
                    if (response.ok) setTraffic(await response.json());
                } catch {
                    // Keep what is shown; try again next time.
                }
            }

            timer = setTimeout(load, POLL_MS);
        }

        void load();

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [slug]);

    const releases = traffic?.releases ?? [];
    const times = releases[0]?.series.map((p) => p.t * 1000) ?? [];
    const line = (pick: 'requests' | 'errors') =>
        releases.map((r, index) => ({
            label: r.live
                ? t('Release #:number (live)', { number: r.number })
                : t('Release #:number', { number: r.number }),
            color: COLORS[index] ?? COLORS[1],
            values: r.series.map((p) => p[pick]),
        }));

    return (
        <Card
            title={t('Traffic')}
            description={t(
                'Requests per 30 seconds, as nginx saw them, for the last half hour. Releases are shown side by side while a release is being watched or rolled out.',
            )}
        >
            {releases.length === 0 ? (
                <p className="flex items-center gap-2 text-sm text-base-content/70">
                    <Activity className="size-4" aria-hidden />
                    {t(
                        'No requests yet. Traffic appears here once visitors reach the app through one of its domains.',
                    )}
                </p>
            ) : (
                <div className="space-y-5">
                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {releases.map((r) => (
                            <div
                                key={r.number}
                                className="rounded-box border border-base-300 bg-base-100 px-4 py-3"
                            >
                                <dt className="text-xs text-base-content/70">
                                    {r.live
                                        ? t('Release #:number (live)', {
                                              number: r.number,
                                          })
                                        : t('Release #:number', {
                                              number: r.number,
                                          })}
                                </dt>
                                <dd className="font-semibold">
                                    {t(
                                        ':requests requests, :percent% errors, slowest 5% under :ms ms',
                                        {
                                            requests:
                                                r.requests.toLocaleString(),
                                            percent: r.error_percent,
                                            ms: r.p95_ms.toLocaleString(),
                                        },
                                    )}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <TimeSeriesChart
                        title={t('Requests')}
                        times={times}
                        series={line('requests')}
                        format={(v) => `${Math.round(v)}`}
                        yFloor={5}
                        windowMs={30 * 60 * 1000}
                    />
                    <TimeSeriesChart
                        title={t('Errors (5xx)')}
                        times={times}
                        series={line('errors')}
                        format={(v) => `${Math.round(v)}`}
                        yFloor={5}
                        windowMs={30 * 60 * 1000}
                    />
                </div>
            )}
        </Card>
    );
}
