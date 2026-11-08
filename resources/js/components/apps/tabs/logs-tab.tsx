import {
    ArrowDownToLine,
    ChevronDown,
    ChevronUp,
    Download,
    RefreshCw,
    Search,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AppLogController from '@/actions/App/Http/Controllers/AppLogController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import { cleanLog, splitMatches } from '@/lib/logs';
import { cn } from '@/lib/utils';
import type { AppSettings } from '@/types';

const REFRESH_MS = 3000;
const LINE_CHOICES = [200, 500, 1000, 5000];

type LogResponse = { live: boolean; logs: string; error?: string };

/**
 * The running container's output: newest lines at the bottom, searchable with
 * Cmd/Ctrl+F (inside the viewer), refreshed every few seconds while "Follow" is
 * on, and downloadable. Only mounted while the tab is open.
 */
export function LogsTab({ app }: { app: AppSettings }) {
    const { t, tn } = useT();
    const [text, setText] = useState('');
    const [live, setLive] = useState<boolean | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [lines, setLines] = useState(500);
    const [follow, setFollow] = useState(true);
    const [wrap, setWrap] = useState(false);
    const [query, setQuery] = useState('');
    const [current, setCurrent] = useState(0);

    const viewer = useRef<HTMLDivElement>(null);
    const search = useRef<HTMLInputElement>(null);
    const pinned = useRef(true);

    const url = AppLogController.show(app.slug).url;

    const load = useCallback(
        async (signal?: AbortSignal) => {
            setLoading(true);

            try {
                const response = await fetch(`${url}?tail=${lines}`, {
                    credentials: 'same-origin',
                    signal,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const body = (await response.json()) as LogResponse;

                if (!response.ok) {
                    throw new Error(
                        body.error ??
                            t('The logs could not be loaded (:status).', {
                                status: response.status,
                            }),
                    );
                }

                setText(cleanLog(body.logs));
                setLive(body.live);
                setError(null);
            } catch (e) {
                if (e instanceof DOMException && e.name === 'AbortError') {
                    return;
                }

                setError(
                    e instanceof Error
                        ? e.message
                        : t('The logs could not be loaded.'),
                );
            } finally {
                setLoading(false);
            }
        },
        [url, lines, t],
    );

    useEffect(() => {
        const controller = new AbortController();
        void load(controller.signal);

        return () => controller.abort();
    }, [load]);

    // Refresh while following, but not in a background tab.
    useEffect(() => {
        if (!follow) return;

        const timer = setInterval(() => {
            if (!document.hidden) void load();
        }, REFRESH_MS);

        return () => clearInterval(timer);
    }, [follow, load]);

    const { parts, count } = useMemo(
        () => splitMatches(text, query),
        [text, query],
    );
    const shown = count === 0 ? 0 : Math.min(current, count - 1);

    // New search: start at the newest match, which is at the bottom.
    useEffect(() => {
        setCurrent(count === 0 ? 0 : count - 1);
    }, [query, count]);

    // Stay at the bottom while following, unless somebody is searching.
    useEffect(() => {
        const el = viewer.current;

        if (el && follow && query === '' && pinned.current) {
            el.scrollTop = el.scrollHeight;
        }
    }, [text, follow, query]);

    // Scroll the log itself to the match, not the whole page: the search box
    // above it has to stay in view.
    useEffect(() => {
        const el = viewer.current;
        const mark = el?.querySelector<HTMLElement>(`[data-match="${shown}"]`);

        if (el && mark) {
            el.scrollTop = mark.offsetTop - el.clientHeight / 2;
        }
    }, [shown, count, text]);

    const step = (by: number) => {
        if (count === 0) return;

        setFollow(false);
        setCurrent((shown + by + count) % count);
    };

    // Cmd/Ctrl+F searches the log, but only while the viewer or its toolbar has focus.
    const onKeyDown = (event: React.KeyboardEvent) => {
        if (
            (event.metaKey || event.ctrlKey) &&
            event.key.toLowerCase() === 'f'
        ) {
            event.preventDefault();
            search.current?.focus();
            search.current?.select();
        }
    };

    const onScroll = () => {
        const el = viewer.current;

        if (el) {
            pinned.current =
                el.scrollHeight - el.scrollTop - el.clientHeight < 40;
        }
    };

    if (live === false) {
        return (
            <Card
                title={t('Logs')}
                description={t('The output of the running container.')}
            >
                <p className="text-sm text-base-content/70">
                    {t(
                        'There are no logs to show because the app has no running container. They appear here once it is running.',
                    )}
                </p>
            </Card>
        );
    }

    return (
        // The keydown handler only listens for the search shortcut.
        // eslint-disable-next-line jsx-a11y/no-static-element-interactions
        <div onKeyDown={onKeyDown}>
            <Card
                title={t('Logs')}
                description={t(
                    'What the running container printed, newest at the bottom. Apps can print secrets into their logs, so downloads are recorded in the audit log.',
                )}
                actions={
                    <a
                        href={AppLogController.download(app.slug).url}
                        className="btn btn-soft btn-sm"
                    >
                        <Download className="size-4" aria-hidden />
                        {t('Download')}
                    </a>
                }
            >
                {error && <Alert variant="error">{error}</Alert>}

                <div className="flex w-full items-end gap-2">
                    <div className="relative min-w-0 flex-1">
                        <label
                            className="mb-1 label-text block px-0!"
                            htmlFor="log-search"
                        >
                            {t('Search')}
                        </label>
                        <Search
                            className="pointer-events-none absolute bottom-3 left-3 size-4 text-base-content/60"
                            aria-hidden
                        />
                        <input
                            id="log-search"
                            ref={search}
                            type="search"
                            className="input w-full pl-9"
                            placeholder={t('Find in the log (Cmd/Ctrl+F)')}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    step(e.shiftKey ? -1 : 1);
                                }
                            }}
                            autoComplete="off"
                            spellCheck={false}
                        />
                    </div>
                    <Button
                        variant="soft"
                        onClick={() => step(-1)}
                        disabled={count === 0}
                        aria-label={t('Previous match')}
                    >
                        <ChevronUp className="size-4" aria-hidden />
                    </Button>
                    <Button
                        variant="soft"
                        onClick={() => step(1)}
                        disabled={count === 0}
                        aria-label={t('Next match')}
                    >
                        <ChevronDown className="size-4" aria-hidden />
                    </Button>
                </div>
                <p className="text-sm text-base-content/70" aria-live="polite">
                    {query === ''
                        ? tn(
                              'one::count line shown.|other::count lines shown.',
                              text === ''
                                  ? 0
                                  : text.trimEnd().split('\n').length,
                          )
                        : count === 0
                          ? t('No matches.')
                          : t(
                                'Match :current of :total. Enter goes to the next one, Shift+Enter to the previous one.',
                                { current: shown + 1, total: count },
                            )}
                </p>

                <Select
                    label={t('Lines to load')}
                    value={lines}
                    onChange={(e) => setLines(Number(e.target.value))}
                >
                    {LINE_CHOICES.map((n) => (
                        <option key={n} value={n}>
                            {t('Last :number lines', {
                                number: n.toLocaleString(),
                            })}
                        </option>
                    ))}
                </Select>

                <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
                    <label className="flex cursor-pointer items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="switch switch-primary"
                            checked={follow}
                            onChange={(e) => {
                                pinned.current = true;
                                setFollow(e.target.checked);
                            }}
                        />
                        {t('Follow (refresh every :seconds seconds)', {
                            seconds: REFRESH_MS / 1000,
                        })}
                    </label>
                    <label className="flex cursor-pointer items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="switch switch-primary"
                            checked={wrap}
                            onChange={(e) => setWrap(e.target.checked)}
                        />
                        {t('Wrap long lines')}
                    </label>
                    <Button
                        variant="soft"
                        size="sm"
                        onClick={() => void load()}
                        loading={loading}
                    >
                        <RefreshCw className="size-4" aria-hidden />
                        {t('Refresh')}
                    </Button>
                    <Button
                        variant="soft"
                        size="sm"
                        onClick={() => {
                            pinned.current = true;
                            const el = viewer.current;
                            if (el) el.scrollTop = el.scrollHeight;
                        }}
                    >
                        <ArrowDownToLine className="size-4" aria-hidden />
                        {t('Jump to the end')}
                    </Button>
                </div>

                <div
                    ref={viewer}
                    onScroll={onScroll}
                    tabIndex={0}
                    role="log"
                    aria-label={t('Container log')}
                    className="relative max-h-[65vh] min-h-48 overflow-auto rounded-field bg-base-200 p-3 font-mono text-xs"
                >
                    {live === null ? (
                        <p className="text-base-content/70">
                            {t('Loading...')}
                        </p>
                    ) : text === '' ? (
                        <p className="text-base-content/70">
                            {t('The container has not printed anything yet.')}
                        </p>
                    ) : (
                        <pre
                            className={cn(
                                wrap
                                    ? 'break-words whitespace-pre-wrap'
                                    : 'whitespace-pre',
                            )}
                        >
                            {parts.map((part, i) =>
                                part.match === null ? (
                                    part.text
                                ) : (
                                    <mark
                                        key={i}
                                        data-match={part.match}
                                        className={cn(
                                            'rounded-sm px-0.5 text-base-content',
                                            part.match === shown
                                                ? 'bg-primary/60 outline-2 outline-primary'
                                                : 'bg-warning/40',
                                        )}
                                    >
                                        {part.text}
                                    </mark>
                                ),
                            )}
                        </pre>
                    )}
                </div>
            </Card>
        </div>
    );
}
