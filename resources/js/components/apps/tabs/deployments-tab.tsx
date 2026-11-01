import { Link, router } from '@inertiajs/react';
import { GitCommit, RotateCcw } from 'lucide-react';
import DeploymentController from '@/actions/App/Http/Controllers/DeploymentController';
import { DeploymentStatusMark } from '@/components/apps/deployment-status';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate, formatDuration, timeAgo } from '@/lib/format';
import type { AppSettings, DeploymentSummary } from '@/types';

type Props = { app: AppSettings; deployments: DeploymentSummary[] };

function Overview({ app }: { app: AppSettings }) {
    const stats: [string, string][] = [
        ['Live release', app.active_release ? `#${app.active_release}` : '-'],
        ['Type', app.stack ?? 'Detected on first deploy'],
        ['App port', String(app.container_port)],
        ['Branch', app.git_url ? app.git_branch : 'Upload'],
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
            <Card title="Deployments" flush>
                {deployments.length === 0 ? (
                    <p className="p-5 text-sm text-base-content/70 sm:p-6">
                        Nothing deployed yet. Press Deploy to build and start
                        this app.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Release</th>
                                    <th>Commit</th>
                                    <th>When</th>
                                    <th>Took</th>
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
                                            {d.is_rollback && (
                                                <span className="badge-soft badge-neutral ml-2 badge badge-sm">
                                                    rollback
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
                                                        Roll back
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
        </div>
    );
}
