import { Link, usePage } from '@inertiajs/react';
import ServerController from '@/actions/App/Http/Controllers/ServerController';
import ServerDockerController from '@/actions/App/Http/Controllers/ServerDockerController';
import { Alert } from '@/components/ui/alert';
import { useT } from '@/hooks/use-t';
import { formatBytes } from '@/lib/format';
import { useCan } from '@/lib/permissions';
import { Trans } from '@/lib/trans';

/**
 * Shown on every page while the agent or Docker is down, so the outage is
 * never a surprise. The panel itself keeps working either way.
 */
export function HostBanner() {
    const { props, url } = usePage();
    const host = props.hostHealth;
    const { t } = useT();
    const can = useCan();

    if (!host) return null;

    if (host.agent && host.docker) return <DiskBanner />;

    if (!host.agent) {
        return (
            <Alert variant="error">
                <span>
                    <Trans
                        text="The CommyPanel agent is not reachable, so nothing can be deployed or changed on the server. On the server, run <code>systemctl status commypanel-agent</code>."
                        components={{ code: <code className="font-mono" /> }}
                    />
                    {can('server.view') && (
                        <>
                            {' '}
                            <Link
                                href={ServerController.index()}
                                className="link font-medium"
                            >
                                {t('Server overview')}
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
                {t(
                    'Docker is not running. Apps return errors and cannot be deployed until it is back; the panel keeps working.',
                )}
                {can('server.view') && (
                    <>
                        {' '}
                        <Link
                            href={ServerDockerController.index()}
                            className="link font-medium"
                        >
                            {t('See what is wrong')}
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
    const { t } = useT();
    const can = useCan();

    if (!disk || disk.level === 'ok') return null;

    return (
        <Alert variant={disk.level === 'critical' ? 'error' : 'warning'}>
            <span>
                {t(
                    "The server's disk is :percent% full (:free free of :total).",
                    {
                        percent: disk.used_percent,
                        free: formatBytes(disk.free_bytes),
                        total: formatBytes(disk.total_bytes),
                    },
                )}{' '}
                {disk.level === 'critical'
                    ? t('Builds, backups and databases can fail now.')
                    : t('Builds, backups and database servers need room.')}{' '}
                {can('server.view') && (
                    <Link
                        href={ServerController.index()}
                        className="link font-medium"
                    >
                        {t('See what uses the space')}
                    </Link>
                )}
            </span>
        </Alert>
    );
}
