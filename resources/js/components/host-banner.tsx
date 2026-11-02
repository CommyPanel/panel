import { Link, usePage } from '@inertiajs/react';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import ServerDockerController from '@/actions/App/Http/Controllers/ServerDockerController';
import { Alert } from '@/components/ui/alert';
import { formatBytes } from '@/lib/format';
import { useCan } from '@/lib/permissions';

/**
 * Shown on every page while the agent or Docker is down, so the outage is
 * never a surprise. The panel itself keeps working either way.
 */
export function HostBanner() {
    const { props, url } = usePage();
    const host = props.hostHealth;
    const can = useCan();

    if (!host) return null;

    if (host.agent && host.docker) return <DiskBanner />;

    if (!host.agent) {
        return (
            <Alert variant="error">
                <span>
                    The CommyPanel agent is not reachable, so nothing can be
                    deployed or changed on the server. On the server, run{' '}
                    <code className="font-mono">
                        systemctl status commypanel-agent
                    </code>
                    .
                    {can('server.view') && (
                        <>
                            {' '}
                            <Link
                                href={ServerController.index()}
                                className="link font-medium"
                            >
                                Server overview
                            </Link>
                        </>
                    )}
                </span>
            </Alert>
        );
    }

    // The Docker page explains the outage in full.
    if (url.startsWith('/server/docker')) return null;

    return (
        <Alert variant="error">
            <span>
                Docker is not running. Apps return errors and cannot be deployed
                until it is back; the panel keeps working.
                {can('server.view') && (
                    <>
                        {' '}
                        <Link
                            href={ServerDockerController.index()}
                            className="link font-medium"
                        >
                            See what is wrong
                        </Link>
                    </>
                )}
            </span>
        </Alert>
    );
}

/**
 * Shown on every page once the disk that holds the panel's data is nearly
 * full: builds, backups and database servers all write there.
 */
function DiskBanner() {
    const { props } = usePage();
    const disk = props.hostHealth?.disk;
    const can = useCan();

    if (!disk || disk.level === 'ok') return null;

    return (
        <Alert variant={disk.level === 'critical' ? 'error' : 'warning'}>
            <span>
                The server&apos;s disk is {disk.used_percent}% full (
                {formatBytes(disk.free_bytes)} free of{' '}
                {formatBytes(disk.total_bytes)}).{' '}
                {disk.level === 'critical'
                    ? 'Builds, backups and databases can fail now. '
                    : 'Builds, backups and database servers need room. '}
                {can('server.view') && (
                    <Link
                        href={ServerController.index()}
                        className="link font-medium"
                    >
                        See what uses the space
                    </Link>
                )}
            </span>
        </Alert>
    );
}
