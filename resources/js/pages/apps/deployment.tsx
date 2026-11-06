import { Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useRef } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { DeploymentStages } from '@/components/apps/deployment-stages';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/format';
import type { DeploymentDetail } from '@/types';

type Props = {
    app: { slug: string; name: string };
    deployment: DeploymentDetail;
};

// Fast enough that the log feels live; polling stops as soon as the deployment has finished.
const POLL_MS = 1000;

function waitingText(status: DeploymentDetail['status']): string {
    return status === 'queued'
        ? 'Waiting for a worker to pick this up...'
        : 'Working. Output appears here as soon as there is some...';
}

export default function Deployment({ app, deployment }: Props) {
    const logRef = useRef<HTMLPreElement>(null);

    useEffect(() => {
        if (deployment.finished) return;

        const timer = setInterval(
            () => router.reload({ only: ['deployment'] }),
            POLL_MS,
        );

        return () => clearInterval(timer);
    }, [deployment.finished]);

    useEffect(() => {
        const log = logRef.current;
        if (!log) return;

        // Follow the output unless the reader has scrolled up.
        const nearBottom =
            log.scrollHeight - log.scrollTop - log.clientHeight < 80;
        if (nearBottom) log.scrollTop = log.scrollHeight;
    }, [deployment.log]);

    const rows: [string, string][] = [
        ['Started', formatDate(deployment.created_at)],
        ['Finished', formatDate(deployment.finished_at)],
        ['Commit', deployment.commit ?? '-'],
        [
            'Type',
            deployment.is_rollback
                ? 'Rollback'
                : deployment.is_apply
                  ? 'Apply changes'
                  : 'Deploy',
        ],
    ];

    return (
        <AppLayout title={`${app.name} #${deployment.number}`}>
            <PageHeader
                title={`${app.name} #${deployment.number}`}
                badge={<StatusBadge status={deployment.status} />}
                actions={
                    <Link
                        href={AppController.show(app.slug)}
                        className="btn btn-text"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        Back to app
                    </Link>
                }
            />

            {deployment.status === 'failed' && deployment.error && (
                <Alert variant="error">{deployment.error}</Alert>
            )}

            <div className="grid items-start gap-6 xl:grid-cols-3">
                <div className="min-w-0 xl:col-span-2">
                    <Card title="Log">
                        <pre
                            ref={logRef}
                            aria-live="polite"
                            className="max-h-[36rem] min-h-48 w-full overflow-auto rounded-field border border-base-300 bg-base-200 p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap"
                        >
                            {deployment.log || waitingText(deployment.status)}
                        </pre>
                    </Card>
                </div>
                <div className="min-w-0 space-y-6">
                    <Card title="Progress">
                        <DeploymentStages
                            stage={deployment.stage}
                            status={deployment.status}
                            isRollback={
                                deployment.is_rollback || deployment.is_apply
                            }
                            hasReleaseCommand={deployment.has_release_command}
                        />
                    </Card>
                    <Card title="Details">
                        <dl className="divide-y divide-base-300 text-sm">
                            {rows.map(([label, value]) => (
                                <div
                                    key={label}
                                    className="flex justify-between gap-4 py-2"
                                >
                                    <dt className="text-base-content/70">
                                        {label}
                                    </dt>
                                    <dd className="font-medium">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
