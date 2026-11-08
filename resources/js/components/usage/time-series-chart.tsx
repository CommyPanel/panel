import { Table2 } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';

export type ChartSeries = {
    label: string;
    /** A CSS colour, normally one of the theme's --chart-N variables. */
    color: string;
    /** One value per entry of `times`. */
    values: number[];
};

type Props = {
    title: string;
    times: number[];
    series: ChartSeries[];
    format: (value: number) => string;
    /** Fixed top of the scale (for example 100 for a percentage). */
    yMax?: number;
    /** The scale never goes below this top, but grows past it when the data does. */
    yFloor?: number;
    height?: number;
    /** Shown instead of the plot until there is data. */
    emptyText?: string;
    /** How much time the plot covers, in milliseconds (five minutes by default). */
    windowMs?: number;
};

const MARGIN = { top: 8, right: 10, bottom: 20, left: 46 };
const DEFAULT_WINDOW_MS = 5 * 60 * 1000;
const TABLE_ROWS = 12;

/** 1, 2, 2.5, 5 or 10 times a power of ten, at least `value`. */
function niceCeil(value: number): number {
    if (value <= 0) return 1;

    const power = 10 ** Math.floor(Math.log10(value));
    const step = [1, 2, 2.5, 5, 10].find((s) => s * power >= value) ?? 10;

    return step * power;
}

function useWidth<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const element = ref.current;
        if (!element) return;

        setWidth(element.clientWidth);
        const observer = new ResizeObserver(() =>
            setWidth(element.clientWidth),
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return [ref, width] as const;
}

function clock(t: number): string {
    return new Date(t).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

function LineKey({ color }: { color: string }) {
    return (
        <span
            aria-hidden
            className="inline-block h-0.5 w-4 shrink-0 rounded-full"
            style={{ backgroundColor: color }}
        />
    );
}

/**
 * A time series as a line with a light area wash. Hover (or the arrow keys)
 * shows a crosshair and the value of every series at that moment; the same
 * numbers are in the table view. With two or more series a legend names them.
 */
export function TimeSeriesChart({
    title,
    times,
    series,
    format,
    yMax,
    yFloor = 0,
    height = 150,
    emptyText,
    windowMs = DEFAULT_WINDOW_MS,
}: Props) {
    const { t } = useT();
    const [wrap, width] = useWidth<HTMLElement>();
    const [hover, setHover] = useState<number | null>(null);
    const [table, setTable] = useState(false);
    const tableId = useId();

    const count = times.length;
    const last = count - 1;
    const dataMax = Math.max(0, ...series.flatMap((s) => s.values));
    const top = yMax ?? Math.max(yFloor, niceCeil(dataMax * 1.15));

    const plotW = Math.max(0, width - MARGIN.left - MARGIN.right);
    const plotH = height - MARGIN.top - MARGIN.bottom;
    const end = times[last] ?? 0;
    const start = Math.max(times[0] ?? 0, end - windowMs);
    const x = (t: number) =>
        MARGIN.left +
        (end === start ? plotW : ((t - start) / (end - start)) * plotW);
    const y = (v: number) =>
        MARGIN.top + plotH - (Math.min(Math.max(v, 0), top) / top) * plotH;

    const pointerMove = (event: PointerEvent<SVGRectElement>) => {
        if (count === 0) return;

        const box = event.currentTarget.getBoundingClientRect();
        const at =
            start + ((event.clientX - box.left) / box.width) * (end - start);
        let nearest = 0;
        for (let i = 1; i < count; i++) {
            if (Math.abs(times[i] - at) < Math.abs(times[nearest] - at)) {
                nearest = i;
            }
        }
        setHover(nearest);
    };

    const keyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (count === 0) return;

        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            const from = hover ?? last;
            setHover(
                Math.min(
                    Math.max(from + (event.key === 'ArrowLeft' ? -1 : 1), 0),
                    last,
                ),
            );
        } else if (event.key === 'Escape') {
            setHover(null);
        }
    };

    const shown = hover === null || hover > last ? null : hover;
    const ticks = [0, top / 2, top];
    const summary = `${title}: ${series
        .map(
            (s) =>
                `${s.label} ${count > 0 ? format(s.values[last]) : t('no data')}`,
        )
        .join(', ')}`;

    const path = (values: number[], area: boolean) => {
        const line = values
            .map(
                (v, i) =>
                    `${i === 0 ? 'M' : 'L'}${x(times[i]).toFixed(1)} ${y(v).toFixed(1)}`,
            )
            .join(' ');

        return area
            ? `${line} L${x(times[last]).toFixed(1)} ${y(0)} L${x(times[0]).toFixed(1)} ${y(0)} Z`
            : line;
    };

    return (
        <figure ref={wrap} className="min-w-0 space-y-2">
            <figcaption className="min-h-12 space-y-1">
                <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-medium">{title}</span>
                    <button
                        type="button"
                        className="btn btn-circle btn-text btn-xs"
                        aria-pressed={table}
                        aria-controls={tableId}
                        aria-label={`${title}: ${table ? t('show chart') : t('show as table')}`}
                        title={table ? t('Show chart') : t('Show as table')}
                        onClick={() => setTable((v) => !v)}
                    >
                        <Table2 className="size-4" aria-hidden />
                    </button>
                </div>
                {series.length > 1 && (
                    <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-base-content/70">
                        {series.map((s) => (
                            <li
                                key={s.label}
                                className="flex items-center gap-1.5"
                            >
                                <LineKey color={s.color} />
                                <span>{s.label}</span>
                                {count > 0 && (
                                    <span className="font-medium text-base-content">
                                        {format(s.values[last])}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </figcaption>

            <div id={tableId}>
                {table ? (
                    <div className="max-h-48 overflow-auto rounded-field border border-base-300">
                        <table className="table table-xs">
                            <caption className="sr-only">
                                {t(':title, newest first', { title })}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">{t('Time')}</th>
                                    {series.map((s) => (
                                        <th scope="col" key={s.label}>
                                            {s.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {times
                                    .map((time, i) => ({ time, i }))
                                    .slice(-TABLE_ROWS)
                                    .reverse()
                                    .map(({ time, i }) => (
                                        <tr key={time}>
                                            <td>{clock(time)}</td>
                                            {series.map((s) => (
                                                <td key={s.label}>
                                                    {format(s.values[i])}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div
                        className="relative rounded-field outline-offset-2 focus-visible:outline-2 focus-visible:outline-primary"
                        style={{ height }}
                        tabIndex={0}
                        role="group"
                        aria-label={`${summary}. ${t('Use the arrow keys to read earlier values.')}`}
                        onKeyDown={keyDown}
                        onBlur={() => setHover(null)}
                    >
                        {count === 0 ? (
                            <p className="flex h-full items-center justify-center text-sm text-base-content/70">
                                {emptyText ??
                                    t('Waiting for the first readings.')}
                            </p>
                        ) : (
                            width > 0 && (
                                <svg
                                    width={width}
                                    height={height}
                                    aria-hidden
                                    className="block"
                                >
                                    {ticks.map((tick) => (
                                        <g key={tick}>
                                            <line
                                                x1={MARGIN.left}
                                                x2={MARGIN.left + plotW}
                                                y1={y(tick)}
                                                y2={y(tick)}
                                                stroke="var(--color-base-300)"
                                                strokeWidth={1}
                                            />
                                            <text
                                                x={MARGIN.left - 8}
                                                y={y(tick)}
                                                textAnchor="end"
                                                dominantBaseline="middle"
                                                className="fill-base-content/60 text-[11px]"
                                            >
                                                {format(tick)}
                                            </text>
                                        </g>
                                    ))}
                                    <text
                                        x={MARGIN.left}
                                        y={height - 4}
                                        className="fill-base-content/60 text-[11px]"
                                    >
                                        {t(':minutes min ago', {
                                            minutes: Math.max(
                                                1,
                                                Math.round(
                                                    (end - start) / 60000,
                                                ),
                                            ),
                                        })}
                                    </text>
                                    <text
                                        x={MARGIN.left + plotW}
                                        y={height - 4}
                                        textAnchor="end"
                                        className="fill-base-content/60 text-[11px]"
                                    >
                                        {t('now')}
                                    </text>

                                    {series.map((s) => (
                                        <g key={s.label}>
                                            <path
                                                d={path(s.values, true)}
                                                fill={s.color}
                                                opacity={0.1}
                                            />
                                            <path
                                                d={path(s.values, false)}
                                                fill="none"
                                                stroke={s.color}
                                                strokeWidth={2}
                                                strokeLinejoin="round"
                                                strokeLinecap="round"
                                            />
                                        </g>
                                    ))}

                                    {shown === null ? (
                                        series.map((s) => (
                                            <g key={s.label}>
                                                <circle
                                                    cx={x(times[last])}
                                                    cy={y(s.values[last])}
                                                    r={6}
                                                    fill="var(--color-base-100)"
                                                />
                                                <circle
                                                    cx={x(times[last])}
                                                    cy={y(s.values[last])}
                                                    r={4}
                                                    fill={s.color}
                                                />
                                            </g>
                                        ))
                                    ) : (
                                        <>
                                            <line
                                                x1={x(times[shown])}
                                                x2={x(times[shown])}
                                                y1={MARGIN.top}
                                                y2={MARGIN.top + plotH}
                                                stroke="var(--color-base-content)"
                                                strokeOpacity={0.35}
                                                strokeWidth={1}
                                            />
                                            {series.map((s) => (
                                                <g key={s.label}>
                                                    <circle
                                                        cx={x(times[shown])}
                                                        cy={y(s.values[shown])}
                                                        r={6}
                                                        fill="var(--color-base-100)"
                                                    />
                                                    <circle
                                                        cx={x(times[shown])}
                                                        cy={y(s.values[shown])}
                                                        r={4}
                                                        fill={s.color}
                                                    />
                                                </g>
                                            ))}
                                        </>
                                    )}

                                    <rect
                                        x={MARGIN.left}
                                        y={MARGIN.top}
                                        width={plotW}
                                        height={plotH}
                                        fill="transparent"
                                        onPointerMove={pointerMove}
                                        onPointerLeave={() => setHover(null)}
                                    />
                                </svg>
                            )
                        )}

                        {shown !== null && width > 0 && (
                            <div
                                role="status"
                                className={cn(
                                    'pointer-events-none absolute top-2 z-10 min-w-36 rounded-field border border-base-300 bg-base-100 px-3 py-2 text-xs shadow-lg',
                                )}
                                style={
                                    x(times[shown]) > width / 2
                                        ? {
                                              right:
                                                  width - x(times[shown]) + 12,
                                          }
                                        : { left: x(times[shown]) + 12 }
                                }
                            >
                                <div className="mb-1 text-base-content/70">
                                    {clock(times[shown])}
                                </div>
                                {series.map((s) => (
                                    <div
                                        key={s.label}
                                        className="flex items-center gap-2"
                                    >
                                        <LineKey color={s.color} />
                                        <span className="font-semibold">
                                            {format(s.values[shown])}
                                        </span>
                                        <span className="text-base-content/70">
                                            {s.label}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </figure>
    );
}
