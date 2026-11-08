import {
    AlertTriangle,
    CheckCircle2,
    CircleDashed,
    CloudCog,
    Lock,
    LockOpen,
    XCircle,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '@/hooks/use-t';
import type { DomainStatus, DomainTls } from '@/types';

type Mark = { icon: ReactNode; label: string; tone: string };

type Translate = (key: string) => string;

const dnsMarks = (t: Translate): Record<DomainStatus, Mark> => ({
    verified: {
        icon: <CheckCircle2 className="size-5" aria-hidden />,
        label: t('Connected'),
        tone: 'text-success',
    },
    pending: {
        icon: <CircleDashed className="size-5" aria-hidden />,
        label: t('Waiting for DNS'),
        tone: 'text-base-content/60',
    },
    misconfigured: {
        icon: <AlertTriangle className="size-5" aria-hidden />,
        label: t('Needs attention'),
        tone: 'text-warning',
    },
    proxied: {
        icon: <CloudCog className="size-5" aria-hidden />,
        label: t('Behind a proxy'),
        tone: 'text-info',
    },
});

const tlsMarks = (t: Translate): Record<DomainTls, Mark> => ({
    secured: {
        icon: <Lock className="size-5" aria-hidden />,
        label: t('HTTPS'),
        tone: 'text-success',
    },
    pending: {
        icon: <CircleDashed className="size-5 animate-pulse" aria-hidden />,
        label: t('Requesting'),
        tone: 'text-info',
    },
    failed: {
        icon: <XCircle className="size-5" aria-hidden />,
        label: t('Failed'),
        tone: 'text-error',
    },
    none: {
        icon: <LockOpen className="size-5" aria-hidden />,
        label: t('No HTTPS'),
        tone: 'text-base-content/60',
    },
});

function Shown({ mark }: { mark: Mark }) {
    return (
        <span className={`inline-flex items-center gap-2 ${mark.tone}`}>
            {mark.icon}
            <span className="text-sm font-medium whitespace-nowrap">
                {mark.label}
            </span>
        </span>
    );
}

/** DNS state with an icon and a word, so colour is never the only signal. */
export function DomainStatusMark({ status }: { status: DomainStatus }) {
    const { t } = useT();

    return <Shown mark={dnsMarks(t)[status]} />;
}

export function DomainTlsMark({ tls }: { tls: DomainTls }) {
    const { t } = useT();

    return <Shown mark={tlsMarks(t)[tls]} />;
}
