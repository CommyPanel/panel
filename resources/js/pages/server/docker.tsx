import { Link, router, usePage } from '@inertiajs/react';
import { FileText, RotateCw, Trash } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import ServerDockerController from '@/actions/App/Http/Controllers/ServerDockerController';
import { ContainerFacts } from '@/components/containers/container-facts';
import { NetworkList } from '@/components/containers/network-list';
import { ServerLayout } from '@/components/server/server-layout';
import { StateMark, toneOfContainer } from '@/components/server/state-mark';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { Modal } from '@/components/ui/modal';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import { formatBytes, formatDate, timeAgo } from '@/lib/format';
import { useCan } from '@/lib/permissions';
import type {
    ContainerMount,
    ContainerNetwork,
    ContainerPort,
    DockerNetwork,
} from '@/types';

type Container = {
    name: string;
    image: string;
    state: string;
    status: string;
    created: number;
    app: { slug: string; name: string } | null;
    release: number | null;
    networks: ContainerNetwork[];
    ports: ContainerPort[];
    mounts: ContainerMount[];
};

type Props = {
    error: string | null;
    docker: {
        available: boolean;
        error: string | null;
        server_version: string;
        api_version: string;
        os: string;
        arch: string;
        kernel_version: string;
        storage_driver: string;
        cgroup_driver: string;
        containers: number;
        running: number;
        paused: number;
        stopped: number;
        images: number;
        cpus: number;
        mem_total: number;
    } | null;
    diagnosis: Diagnosis | null;
    containers: Container[];
    networks: DockerNetwork[];
    images: { tags: string[]; size: number; created: number }[];
};

type Diagnosis = {
    error: string | null;
    service: string;
    socket: string;
    journal: string;
};

/** Why Docker is down, from systemd, and a way to bring it back. */
function DockerDown({
    error,
    diagnosis,
}: {
    error: string | null;
    diagnosis: Diagnosis | null;
}) {
    const { t } = useT();
    const can = useCan();
    const confirmed = useConfirmedAction();
    const [asking, setAsking] = useState(false);
    const [restarting, setRestarting] = useState(false);

    const restart = () =>
        confirmed.run(() =>
            router.post(
                ServerDockerController.restart(),
                {},
                {
                    preserveScroll: true,
                    onStart: () => setRestarting(true),
                    onFinish: () => {
                        setRestarting(false);
                        setAsking(false);
                    },
                },
            ),
        );

    return (
        <Card
            title={t('Docker is not running')}
            description={t(
                'Apps cannot be built, started or reached until Docker is back. The panel and nginx keep working.',
            )}
        >
            {error && (
                <p className="text-sm">
                    <span className="text-base-content/70">{t('Error:')} </span>
                    <span className="font-mono break-all">{error}</span>
                </p>
            )}
            {diagnosis && (
                <>
                    <dl className="grid gap-1 text-sm sm:grid-cols-[10rem_1fr]">
                        <dt className="text-base-content/70">docker.service</dt>
                        <dd className="font-mono">{diagnosis.service}</dd>
                        <dt className="text-base-content/70">docker.socket</dt>
                        <dd className="font-mono">{diagnosis.socket}</dd>
                    </dl>
                    {diagnosis.journal && (
                        <div className="space-y-1">
                            <p className="text-sm font-medium">
                                {t('Latest Docker log lines')}
                            </p>
                            <pre className="max-h-80 overflow-auto rounded-box bg-base-200 p-3 font-mono text-xs whitespace-pre-wrap">
                                {diagnosis.journal}
                            </pre>
                        </div>
                    )}
                </>
            )}
            {can('server.manage') && (
                <div>
                    <Button
                        variant="warning"
                        onClick={() => setAsking(true)}
                        loading={restarting}
                    >
                        <RotateCw className="size-4" aria-hidden />
                        {t('Restart Docker')}
                    </Button>
                </div>
            )}
            <ConfirmModal
                open={asking}
                title={t('Restart Docker?')}
                confirmLabel={t('Restart Docker')}
                loading={restarting}
                onConfirm={restart}
                onClose={() => setAsking(false)}
            >
                {t(
                    'Every container stops and starts again, so all apps are unavailable for a moment. If Docker keeps failing, the log lines above usually say why.',
                )}
            </ConfirmModal>
            {confirmed.modal}
        </Card>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0 rounded-box border border-base-300 px-4 py-3">
            <dt className="text-xs text-base-content/70">{label}</dt>
            <dd className="truncate font-semibold">{value}</dd>
        </div>
    );
}

function LogsModal({
    name,
    onClose,
}: {
    name: string | null;
    onClose: () => void;
}) {
    const { t } = useT();
    const [logs, setLogs] = useState<{ name: string; text: string } | null>(
        null,
    );
    const [problem, setProblem] = useState<{
        name: string;
        message: string;
    } | null>(null);

    // Load when a container is chosen; a late answer for another one is ignored.
    useEffect(() => {
        if (name === null) return;

        let current = true;

        fetch(ServerDockerController.logs(name).url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                const body = (await response.json()) as {
                    logs?: string;
                    error?: string;
                };
                if (!response.ok) throw new Error(body.error);
                if (current) setLogs({ name, text: body.logs ?? '' });
            })
            .catch((e: Error) => {
                if (current) {
                    setProblem({
                        name,
                        message: e.message || t('Could not load the logs.'),
                    });
                }
            });

        return () => {
            current = false;
        };
    }, [name, t]);

    return (
        <Modal
            open={name !== null}
            onClose={onClose}
            title={name ? t('Logs of :name', { name }) : t('Logs')}
            size="lg"
        >
            {problem && problem.name === name ? (
                <Alert variant="error">{problem.message}</Alert>
            ) : logs && logs.name === name ? (
                <pre className="max-h-96 overflow-auto rounded-field border border-base-300 p-3 text-xs">
                    {logs.text ||
                        t('The container has not printed anything yet.')}
                </pre>
            ) : (
                <p className="text-sm text-base-content/70">{t('Loading…')}</p>
            )}
        </Modal>
    );
}

export default function Docker({
    error,
    docker,
    diagnosis,
    containers,
    networks,
    images,
}: Props) {
    const { t } = useT();
    const { errors } = usePage().props;
    const [logsFor, setLogsFor] = useState<string | null>(null);
    const [pruning, setPruning] = useState(false);

    const prune = () =>
        router.post(
            ServerDockerController.prune(),
            {},
            {
                preserveScroll: true,
                onStart: () => setPruning(true),
                onFinish: () => setPruning(false),
            },
        );

    return (
        <ServerLayout active="docker" error={error}>
            {errors.docker && <Alert variant="error">{errors.docker}</Alert>}

            {docker && !docker.available && (
                <DockerDown error={docker.error} diagnosis={diagnosis} />
            )}

            {docker?.available && (
                <>
                    <Card
                        title="Docker"
                        description={t('Engine :version, API :api, :os/:arch', {
                            version: docker.server_version,
                            api: docker.api_version,
                            os: docker.os,
                            arch: docker.arch,
                        })}
                    >
                        <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <Stat
                                label={t('Running')}
                                value={t(':running of :total', {
                                    running: docker.running,
                                    total: docker.containers,
                                })}
                            />
                            <Stat
                                label={t('Stopped')}
                                value={String(docker.stopped)}
                            />
                            <Stat
                                label={t('Images')}
                                value={String(docker.images)}
                            />
                            <Stat
                                label={t('Storage driver')}
                                value={docker.storage_driver}
                            />
                            <Stat
                                label={t('Cgroup driver')}
                                value={docker.cgroup_driver}
                            />
                            <Stat
                                label={t('CPUs')}
                                value={String(docker.cpus)}
                            />
                            <Stat
                                label={t('Memory')}
                                value={formatBytes(docker.mem_total)}
                            />
                            <Stat
                                label={t('Kernel')}
                                value={docker.kernel_version}
                            />
                        </dl>
                    </Card>

                    <Card
                        title={t('Containers')}
                        description={t(
                            'The containers CommyPanel started for your apps.',
                        )}
                        flush
                    >
                        {containers.length === 0 ? (
                            <p className="p-5 text-sm text-base-content/70 sm:p-6">
                                {t(
                                    'No containers yet. Deploy an app and it shows up here.',
                                )}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="table">
                                    <thead>
                                        <tr>
                                            <th>{t('State')}</th>
                                            <th>{t('Container')}</th>
                                            <th>{t('App')}</th>
                                            <th>{t('Image')}</th>
                                            <th>{t('Since')}</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {containers.map((c) => (
                                            <tr key={c.name}>
                                                <td>
                                                    <StateMark
                                                        tone={toneOfContainer(
                                                            c.state,
                                                        )}
                                                        label={c.state}
                                                    />
                                                </td>
                                                <td>
                                                    <code>{c.name}</code>
                                                    <p className="mb-2 text-xs text-base-content/60">
                                                        {c.status}
                                                    </p>
                                                    <ContainerFacts
                                                        networks={c.networks}
                                                        ports={c.ports}
                                                        mounts={c.mounts}
                                                    />
                                                </td>
                                                <td>
                                                    {c.app ? (
                                                        <Link
                                                            href={AppController.show(
                                                                c.app.slug,
                                                            )}
                                                            className="link link-primary"
                                                        >
                                                            {c.app.name}
                                                            {c.release
                                                                ? ` #${c.release}`
                                                                : ''}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-base-content/60">
                                                            -
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="break-all">
                                                    <code className="text-xs">
                                                        {c.image}
                                                    </code>
                                                </td>
                                                <td
                                                    className="whitespace-nowrap"
                                                    title={formatDate(
                                                        new Date(
                                                            c.created * 1000,
                                                        ).toISOString(),
                                                    )}
                                                >
                                                    {timeAgo(
                                                        new Date(
                                                            c.created * 1000,
                                                        ).toISOString(),
                                                    )}
                                                </td>
                                                <td className="text-right">
                                                    <Button
                                                        variant="soft"
                                                        size="sm"
                                                        onClick={() =>
                                                            setLogsFor(c.name)
                                                        }
                                                    >
                                                        <FileText
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        {t('Logs')}
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>

                    <Card
                        title={t('Networks')}
                        description={t(
                            'The Docker networks CommyPanel created and what is attached to them.',
                        )}
                    >
                        {networks.length === 0 ? (
                            <p className="text-sm text-base-content/70">
                                {t(
                                    'No networks yet. One is created with the first app.',
                                )}
                            </p>
                        ) : (
                            <NetworkList networks={networks} />
                        )}
                    </Card>

                    <Card
                        title={t('Images')}
                        description={t(
                            'Images built from your apps. Old releases keep theirs so you can roll back.',
                        )}
                        actions={
                            <Button
                                variant="soft"
                                size="sm"
                                onClick={prune}
                                loading={pruning}
                            >
                                <Trash className="size-4" aria-hidden />
                                {t('Remove unused images')}
                            </Button>
                        }
                        flush
                    >
                        {images.length === 0 ? (
                            <p className="p-5 text-sm text-base-content/70 sm:p-6">
                                {t('No images yet.')}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="table">
                                    <thead>
                                        <tr>
                                            <th>{t('Image')}</th>
                                            <th>{t('Size')}</th>
                                            <th>{t('Built')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {images.map((image) => (
                                            <tr key={image.tags.join()}>
                                                <td className="break-all">
                                                    <code>
                                                        {image.tags.join(', ')}
                                                    </code>
                                                </td>
                                                <td className="whitespace-nowrap">
                                                    {formatBytes(image.size)}
                                                </td>
                                                <td className="whitespace-nowrap">
                                                    {timeAgo(
                                                        new Date(
                                                            image.created *
                                                                1000,
                                                        ).toISOString(),
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </>
            )}

            <LogsModal name={logsFor} onClose={() => setLogsFor(null)} />
        </ServerLayout>
    );
}
