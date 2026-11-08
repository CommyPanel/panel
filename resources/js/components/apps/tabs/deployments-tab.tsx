import { Link, router } from '@inertiajs/react';
import { GitCommit, Play, RotateCcw, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import DeploymentController from '@/actions/App/Http/Controllers/DeploymentController';
import { DeploymentStatusMark } from '@/components/apps/deployment-status';
import { RetryModal } from '@/components/apps/safe-deploy-panels';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import { formatDate, formatDuration, timeAgo } from '@/lib/format';
import type { AppSettings, DeploymentSummary } from '@/types';

type Props = { app: AppSettings; deployments: DeploymentSummary[] };

function Overview({ app }: { app: AppSettings }) {
    const { t } = useT();
    const stats: [string, string][] = [
        [
            t('Live release'),
            app.active_release ? `#${app.active_release}` : '-',
        ],
        [t('Type'), app.stack ?? t('Detected on first deploy')],
        [t('App port'), String(app.container_port)],
        [t('Branch'), app.git_url ? app.git_branch : t('Upload')],
    ];

    return (
        <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {stats.map(([label, value]) => (
                <div
                    key={label}
                    className="min-w-0 rounded-box border border-base-300 bg-base-100 px-4 py-3"
                >
                    <dt className="text-xs text-base-content/70">{label}</dt>
                    <dd className="truncate font-semibold">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

export function DeploymentsTab({ app, deployments }: Props) {
    const { t } = useT();
    const [retrying, setRetrying] = useState<number | null>(null);
    const rollBack = (number: number) =>
        router.post(
            DeploymentController.rollback({
                app: app.slug,
                deployment: number,
            }),
            {},
            { preserveScroll: true },
        );

    return (
        <div className="space-y-6">
            <Overview app={app} />
            <Card title={t('Deployments')} flush>
                {deployments.length === 0 ? (
                    <p className="p-5 text-sm text-base-content/70 sm:p-6">
                        {t(
                            'Nothing deployed yet. Press Deploy to build and start this app.',
                        )}
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>{t('Status')}</th>
                                    <th>{t('Release')}</th>
                                    <th>{t('Commit')}</th>
                                    <th>{t('When')}</th>
                                    <th>{t('Took')}</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {deployments.map((d) => (
                                    <tr key={d.number}>
                                        <td>
                                            <DeploymentStatusMark
                                                status={d.status}
                                            />
                                            {d.watch?.state === 'watching' && (
                                                <span
                                                    className="mt-1 flex items-center gap-1 text-xs text-info"
                                                    title={t(
                                                        'The previous version is kept running while this release is watched',
                                                    )}
                                                >
                                                    <ShieldCheck
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                    {t('watching')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap">
                                            <Link
                                                href={DeploymentController.show(
                                                    {
                                                        app: app.slug,
                                                        deployment: d.number,
                                                    },
                                                )}
                                                className="link font-medium link-primary"
                                            >
                                                #{d.number}
                                            </Link>
                                            {d.is_apply && (
                                                <span className="badge-soft badge-neutral ml-2 badge badge-sm">
                                                    {t('applied changes')}
                                                </span>
                                            )}
                                            {d.is_rollback && (
                                                <span className="badge-soft badge-neutral ml-2 badge badge-sm">
                                                    {t('rollback')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="max-w-xs min-w-40">
                                            {d.commit ? (
                                                <div className="space-y-0.5">
                                                    <code className="inline-flex items-center gap-1 text-xs">
                                                        <GitCommit
                                                            className="size-3.5"
                                                            aria-hidden
                                                        />
                                                        {d.commit}
                                                    </code>
                                                    {d.commit_message && (
                                                        <p
                                                            className="truncate text-sm text-base-content/70"
                                                            title={
                                                                d.commit_message
                                                            }
                                                        >
                                                            {d.commit_message}
                                                        </p>
                                                    )}
                                                </div>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td
                                            className="whitespace-nowrap"
                                            title={formatDate(d.created_at)}
                                        >
                                            {timeAgo(d.created_at)}
                                        </td>
                                        <td className="whitespace-nowrap">
                                            {formatDuration(d.duration_seconds)}
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            {d.can_retry && (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        setRetrying(d.number)
                                                    }
                                                >
                                                    <Play
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                    {t('Go live again')}
                                                </Button>
                                            )}
                                            {d.status === 'superseded' &&
                                                d.can_roll_back && (
                                                    <Button
                                                        variant="soft"
                                                        size="sm"
                                                        onClick={() =>
                                                            rollBack(d.number)
                                                        }
                                                    >
                                                        <RotateCcw
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                        {t('Roll back')}
                                                    </Button>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
            {retrying !== null && (
                <RetryModal
                    open
                    onClose={() => setRetrying(null)}
                    slug={app.slug}
                    number={retrying}
                />
            )}
        </div>
    );
}
