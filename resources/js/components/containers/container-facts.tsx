import { ArrowRight } from 'lucide-react';
import { useT } from '@/hooks/use-t';
import type {
    ContainerMount,
    ContainerNetwork,
    ContainerPort,
    DockerNetwork,
} from '@/types';

type Facts = {
    networks: ContainerNetwork[];
    ports: ContainerPort[];
    mounts: ContainerMount[];
};

/** What a container is attached to: networks with its address, published ports and volumes. */
export function ContainerFacts({ networks, ports, mounts }: Facts) {
    const { t } = useT();
    const rows: { label: string; items: React.ReactNode[] }[] = [
        {
            label: t('Networks'),
            items: networks.map((n) => (
                <span key={n.name}>
                    <code>{n.name}</code>
                    {n.ip && (
                        <span className="text-base-content/70"> · {n.ip}</span>
                    )}
                </span>
            )),
        },
        {
            label: t('Ports'),
            items: ports.map((p) => (
                <span
                    key={`${p.host}-${p.container}`}
                    className="inline-flex items-center gap-1"
                >
                    <code>
                        {p.host_ip || '127.0.0.1'}:{p.host}
                    </code>
                    <ArrowRight className="size-3" aria-hidden />
                    <code>
                        {p.container}/{p.protocol}
                    </code>
                </span>
            )),
        },
        {
            label: t('Volumes'),
            items: mounts.map((m) => (
                <span key={m.destination}>
                    <code>{m.destination}</code>
                    <span className="text-base-content/70">
                        {' '}
                        ← volumes/{m.source}
                        {m.read_only ? ` (${t('read only')})` : ''}
                    </span>
                </span>
            )),
        },
    ];

    return (
        <dl className="space-y-1 text-xs">
            {rows.map((row) => (
                <div key={row.label} className="flex flex-wrap gap-x-2">
                    <dt className="w-16 shrink-0 text-base-content/70">
                        {row.label}
                    </dt>
                    <dd className="flex min-w-0 flex-wrap gap-x-3 gap-y-0.5 break-all">
                        {row.items.length === 0 ? (
                            <span className="text-base-content/60">
                                {t('none')}
                            </span>
                        ) : (
                            row.items
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

/** More than one app on a network means those apps can reach each other. */
export function appsOn(network: DockerNetwork): number {
    return new Set(
        network.containers
            .map((c) => c.app?.slug)
            .filter((slug): slug is string => Boolean(slug)),
    ).size;
}
