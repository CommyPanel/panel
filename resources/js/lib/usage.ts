import { useEffect, useRef, useState } from 'react';
import { formatBytes } from '@/lib/format';

/** One reading of CPU (%), memory (bytes) and network (bytes per second). */
export type UsagePoint = {
    t: number;
    cpu: number;
    mem: number;
    rx: number;
    tx: number;
};

export type HostUsage = {
    ready: boolean;
    cpu_percent: number;
    cpus: number;
    mem_total: number;
    mem_used: number;
    disk_total: number;
    disk_used: number;
    net_rx_bps: number;
    net_tx_bps: number;
};

export type ContainerUsage = {
    name: string;
    t: number;
    /** Percent of one core: 100 is one full core. */
    cpu_percent: number;
    mem_used: number;
    mem_limit: number;
    net_rx_bps: number;
    net_tx_bps: number;
    history: UsagePoint[];
};

export type ServerUsage = {
    interval_ms: number;
    now: number;
    host: HostUsage;
    host_history: UsagePoint[];
    containers: (ContainerUsage & {
        app: { slug: string; name: string } | null;
        release: number | null;
    })[];
};

export type AppUsage = {
    live: boolean;
    interval_ms: number;
    /** Null while the container is stopped or has not been read twice yet. */
    container: ContainerUsage | null;
};

/** The most points a chart keeps: five minutes at the agent's 2 second pace. */
export const MAX_POINTS = 150;

export function formatPercent(value: number): string {
    return `${value >= 10 ? Math.round(value) : value.toFixed(1)}%`;
}

export function formatRate(bytesPerSecond: number): string {
    return bytesPerSecond < 1 ? '0 B/s' : `${formatBytes(bytesPerSecond)}/s`;
}

type Poll<T> = {
    /** The first answer, which includes the history. */
    first: T | null;
    latest: T | null;
    error: string | null;
};

/**
 * Polls a usage endpoint: the first request also asks for the last few
 * minutes, later ones only for the newest reading. Polling pauses while the
 * tab is hidden and the last data stays on screen if a request fails.
 */
export function useUsagePoll<T extends { interval_ms: number }>(
    url: string,
): Poll<T> {
    const [state, setState] = useState<Poll<T>>({
        first: null,
        latest: null,
        error: null,
    });

    useEffect(() => {
        const controller = new AbortController();
        let timer: ReturnType<typeof setTimeout> | undefined;
        let interval = 2000;
        let haveFirst = false;

        const schedule = () => {
            timer = setTimeout(load, interval);
        };

        async function load() {
            if (controller.signal.aborted) return;
            if (document.hidden) {
                schedule();

                return;
            }

            try {
                const response = await fetch(
                    haveFirst ? url : `${url}?history=1`,
                    {
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: controller.signal,
                    },
                );
                const body = (await response.json()) as T & { error?: string };

                if (!response.ok) {
                    throw new Error(
                        body.error ?? `Request failed (${response.status})`,
                    );
                }

                interval = Math.max(1000, body.interval_ms || 2000);
                const isFirst = !haveFirst;
                haveFirst = true;
                setState((s) => ({
                    first: isFirst ? body : s.first,
                    latest: body,
                    error: null,
                }));
            } catch (error) {
                if (controller.signal.aborted) return;
                setState((s) => ({
                    ...s,
                    error:
                        error instanceof Error
                            ? error.message
                            : 'Could not read usage.',
                }));
            }

            schedule();
        }

        const onVisible = () => {
            if (!document.hidden) {
                clearTimeout(timer);
                void load();
            }
        };
        document.addEventListener('visibilitychange', onVisible);
        void load();

        return () => {
            controller.abort();
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [url]);

    return state;
}

/**
 * Builds a chart series from the first answer's history, then appends the
 * newest reading of every later answer. `pick` must not depend on anything
 * but its argument.
 */
export function useSeries<T>(
    first: T | null,
    latest: T | null,
    pick: (response: T) => { history: UsagePoint[]; point: UsagePoint | null },
): UsagePoint[] {
    const [series, setSeries] = useState<UsagePoint[]>([]);
    const pickRef = useRef(pick);

    useEffect(() => {
        if (first) setSeries(pickRef.current(first).history);
    }, [first]);

    useEffect(() => {
        if (!latest || latest === first) return;
        const { point } = pickRef.current(latest);
        if (!point) return;

        setSeries((current) =>
            current.length > 0 && current[current.length - 1].t >= point.t
                ? current
                : [...current, point].slice(-MAX_POINTS),
        );
    }, [latest, first]);

    return series;
}
