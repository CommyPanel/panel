import { Link } from '@inertiajs/react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import { Alert } from '@/components/ui/alert';
import type { DockerNetwork } from '@/types';
import { appsOn } from './container-facts';

/**
 * Networks and what is attached to them. Containers on the same network can
 * reach each other on any port, so a network shared by several apps is called out.
 */
export function NetworkList({ networks }: { networks: DockerNetwork[] }) {
    return (
        <div className="space-y-4">
            {networks.map((network) => (
                <section key={network.name} className="space-y-2">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 className="font-medium">
                            <code>{network.name}</code>
                        </h3>
                        <p className="text-xs text-base-content/70">
                            {network.driver}
                            {network.subnet ? ` · ${network.subnet}` : ''}
                            {network.gateway
                                ? ` · gateway ${network.gateway}`
                                : ''}
                            {network.internal ? ' · internal' : ''}
                        </p>
                    </div>
                    {appsOn(network) > 1 && (
                        <Alert variant="info">
                            {appsOn(network)} apps share this network, so their
                            containers can reach each other directly. Keep that
                            in mind if one of them is not fully trusted.
                        </Alert>
                    )}
                    {network.containers.length === 0 ? (
                        <p className="text-sm text-base-content/70">
                            Nothing is attached.
                        </p>
                    ) : (
                        <ul className="divide-y divide-base-300 rounded-field border border-base-300 text-sm">
                            {network.containers.map((member) => (
                                <li
                                    key={member.name}
                                    className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3 py-2"
                                >
                                    <span className="flex items-center gap-2">
                                        <code>{member.name}</code>
                                        {member.app && (
                                            <Link
                                                href={AppController.show(
                                                    member.app.slug,
                                                )}
                                                className="link text-xs link-primary"
                                            >
                                                {member.app.name}
                                            </Link>
                                        )}
                                        {member.this_app && (
                                            <span className="badge-soft badge badge-sm badge-primary">
                                                this app
                                            </span>
                                        )}
                                    </span>
                                    <code className="text-xs text-base-content/70">
                                        {member.ip}
                                    </code>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            ))}
        </div>
    );
}
