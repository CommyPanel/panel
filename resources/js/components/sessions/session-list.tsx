import { Laptop, LogOut } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useT } from '@/hooks/use-t';
import { formatDate } from '@/lib/format';

export type SessionRow = {
    handle: string;
    ip: string | null;
    browser: string | null;
    os: string | null;
    user_agent: string | null;
    last_active: string;
    current: boolean;
};

type Props = {
    sessions: SessionRow[];
    /** Called with the handle of the browser to sign out; omit to show a read-only list. */
    onRevoke?: (handle: string) => void;
};

/** The signed-in browsers of one account: where, with what, and when last active. */
export function SessionList({ sessions, onRevoke }: Props) {
    const { t } = useT();

    if (sessions.length === 0) {
        return (
            <p className="text-sm text-base-content/70">
                {t('No active sessions.')}
            </p>
        );
    }

    return (
        <ul className="divide-y divide-base-300">
            {sessions.map((session) => {
                const label =
                    session.browser && session.os
                        ? t(':browser on :os', {
                              browser: session.browser,
                              os: session.os,
                          })
                        : (session.browser ??
                          session.os ??
                          t('Unknown browser'));

                return (
                    <li
                        key={session.handle}
                        className="flex flex-wrap items-center gap-3 py-3"
                    >
                        <Laptop
                            className="size-5 shrink-0 text-base-content/60"
                            aria-hidden
                        />
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2 font-medium">
                                <span title={session.user_agent ?? undefined}>
                                    {label}
                                </span>
                                {session.current && (
                                    <span className="badge-soft badge badge-success">
                                        {t('This browser')}
                                    </span>
                                )}
                            </div>
                            <div className="text-sm break-all text-base-content/70">
                                {session.ip ?? t('Unknown address')}
                                {' · '}
                                {t('Last active :date', {
                                    date: formatDate(session.last_active),
                                })}
                            </div>
                        </div>
                        {onRevoke && !session.current && (
                            <Button
                                variant="soft"
                                size="sm"
                                onClick={() => onRevoke(session.handle)}
                            >
                                <LogOut className="size-4" aria-hidden />
                                {t('Sign out')}
                            </Button>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
