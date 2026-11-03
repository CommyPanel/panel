import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const REFRESH_MS = 5000;
const CHECK_MS = 15000;
const GIVE_UP_MS = 30 * 60 * 1000;

/**
 * Keeps a page that waits for something (DNS to update, a certificate to be
 * issued) up to date without anyone pressing a button: it asks the server for
 * the page's data every 5 seconds, and, if `checks` is given, runs it every
 * 15 seconds first (a real DNS check). It pauses while the tab is hidden and
 * gives up after half an hour, when a person's own click is the better way on.
 *
 * The server also re-checks everything every five minutes by itself, so a page
 * left open or closed makes no difference to the outcome, only to what is shown.
 */
export function useProgress({
    active,
    only,
    checks,
}: {
    active: boolean;
    only: string[];
    checks?: () => Promise<unknown>;
}) {
    const [gaveUp, setGaveUp] = useState(false);
    const latestChecks = useRef(checks);
    latestChecks.current = checks;
    const onlyKey = only.join(',');

    useEffect(() => {
        if (!active) {
            setGaveUp(false);

            return;
        }

        const started = Date.now();
        let lastCheck = started;
        let busy = false;

        const timer = setInterval(async () => {
            if (document.hidden || busy) return;

            if (Date.now() - started > GIVE_UP_MS) {
                setGaveUp(true);
                clearInterval(timer);

                return;
            }

            busy = true;

            try {
                if (
                    latestChecks.current &&
                    Date.now() - lastCheck >= CHECK_MS
                ) {
                    lastCheck = Date.now();
                    await latestChecks.current().catch(() => undefined);
                }

                router.reload({ only: onlyKey.split(',') });
            } finally {
                busy = false;
            }
        }, REFRESH_MS);

        return () => clearInterval(timer);
    }, [active, onlyKey]);

    return { waiting: active && !gaveUp, gaveUp };
}
