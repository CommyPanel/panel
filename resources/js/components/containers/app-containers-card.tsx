import { useCallback, useEffect, useState } from 'react';
import AppContainerController from '@/actions/App/Http/Controllers/AppContainerController';
import { StateMark, toneOfContainer } from '@/components/server/state-mark';
import { Alert } from '@/components/ui/alert';
import { Card } from '@/components/ui/card';
import type {
    ContainerMount,
    ContainerNetwork,
    ContainerPort,
    DockerNetwork,
} from '@/types';
import { ContainerFacts } from './container-facts';
import { NetworkList } from './network-list';

type AppContainer = {
    name: string;
    image: string;
    state: string;
    status: string;
    release: number | null;
    live: boolean;
    networks: ContainerNetwork[];
    ports: ContainerPort[];
    mounts: ContainerMount[];
};

type Payload = { containers: AppContainer[]; networks: DockerNetwork[] };

/**
 * The app's containers (the live release, and an older one while it drains),
 * what they are attached to, and what else is on their networks.
 */
export function AppContainersCard({ slug }: { slug: string }) {
    const [data, setData] = useState<Payload | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(async () => {
        try {
            const response = await fetch(
                AppContainerController.show(slug).url,
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );
            const body = (await response.json()) as Payload & {
                error?: string;
            };

            if (!response.ok) {
                throw new Error(
                    body.error ?? `The request failed (${response.status}).`,
                );
            }

            setData(body);
            setError(null);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'The containers could not be loaded.',
            );
        }
    }, [slug]);

    useEffect(() => {
        void load();
    }, [load]);

    return (
        <Card
            title="Containers and networks"
            description="What this app runs as, and what it is connected to."
        >
            {error && <Alert variant="error">{error}</Alert>}
            {!error && data === null && (
                <p className="text-sm text-base-content/70" role="status">
                    Loading...
                </p>
            )}
            {data && data.containers.length === 0 && (
                <p className="text-sm text-base-content/70">
                    This app has no containers right now.
                </p>
            )}
            {data && data.containers.length > 0 && (
                <>
                    <ul className="divide-y divide-base-300 rounded-field border border-base-300">
                        {data.containers.map((c) => (
                            <li key={c.name} className="space-y-2 p-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StateMark
                                        tone={toneOfContainer(c.state)}
                                        label={c.state}
                                    />
                                    <code className="text-sm">{c.name}</code>
                                    {c.release !== null && (
                                        <span className="text-xs text-base-content/70">
                                            release #{c.release}
                                        </span>
                                    )}
                                    {c.live && (
                                        <span className="badge-soft badge badge-sm badge-primary">
                                            live
                                        </span>
                                    )}
                                </div>
                                <p className="text-xs break-all text-base-content/70">
                                    {c.image} · {c.status}
                                </p>
                                <ContainerFacts
                                    networks={c.networks}
                                    ports={c.ports}
                                    mounts={c.mounts}
                                />
                            </li>
                        ))}
                    </ul>
                    {data.networks.length > 0 && (
                        <NetworkList networks={data.networks} />
                    )}
                </>
            )}
        </Card>
    );
}
