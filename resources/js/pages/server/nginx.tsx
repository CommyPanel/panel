import { router, usePage } from '@inertiajs/react';
import { FlaskConical, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import ServerNginxController from '@/actions/App/Http/Controllers/ServerNginxController';
import { ServerLayout } from '@/components/server/server-layout';
import { StateMark } from '@/components/server/state-mark';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import { formatDate } from '@/lib/format';

type Props = {
    error: string | null;
    nginx: {
        available: boolean;
        running: boolean;
        version: string;
        config_ok: boolean;
        config_output: string;
        sites: {
            name: string;
            domains: string[];
            https: boolean;
            modified: number;
        }[];
    } | null;
};

export default function Nginx({ error, nginx }: Props) {
    const { t } = useT();
    const { errors } = usePage().props;
    const [busy, setBusy] = useState<'test' | 'reload' | null>(null);

    const run = (
        kind: 'test' | 'reload',
        action: typeof ServerNginxController.test,
    ) =>
        router.post(
            action(),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(kind),
                onFinish: () => setBusy(null),
            },
        );

    return (
        <ServerLayout active="nginx" error={error}>
            {errors.nginx && <Alert variant="error">{errors.nginx}</Alert>}

            {nginx && !nginx.available && (
                <Alert variant="error">
                    {t(
                        'nginx is not installed on this server. Apps cannot be reached from the internet until it is.',
                    )}
                </Alert>
            )}

            {nginx && nginx.available && (
                <>
                    <Card
                        title="nginx"
                        description={t(
                            'The web server in front of your apps. It keeps running while Docker is down, so the panel stays reachable.',
                        )}
                        actions={
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="soft"
                                    size="sm"
                                    loading={busy === 'test'}
                                    onClick={() =>
                                        run('test', ServerNginxController.test)
                                    }
                                >
                                    <FlaskConical
                                        className="size-4"
                                        aria-hidden
                                    />
                                    {t('Test configuration')}
                                </Button>
                                <Button
                                    variant="soft"
                                    size="sm"
                                    loading={busy === 'reload'}
                                    onClick={() =>
                                        run(
                                            'reload',
                                            ServerNginxController.reload,
                                        )
                                    }
                                >
                                    <RefreshCw className="size-4" aria-hidden />
                                    {t('Reload')}
                                </Button>
                            </div>
                        }
                    >
                        <dl className="divide-y divide-base-300 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <dt className="text-base-content/70">
                                    {t('Service')}
                                </dt>
                                <dd>
                                    <StateMark
                                        tone={nginx.running ? 'good' : 'bad'}
                                        label={
                                            nginx.running
                                                ? t('Running')
                                                : t('Not running')
                                        }
                                    />
                                </dd>
                            </div>
                            <div className="flex flex-wrap justify-between gap-2 py-2">
                                <dt className="text-base-content/70">
                                    {t('Version')}
                                </dt>
                                <dd className="font-medium">
                                    {nginx.version || '-'}
                                </dd>
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <dt className="text-base-content/70">
                                    {t('Configuration')}
                                </dt>
                                <dd>
                                    <StateMark
                                        tone={nginx.config_ok ? 'good' : 'bad'}
                                        label={
                                            nginx.config_ok
                                                ? t('Valid')
                                                : t('Invalid')
                                        }
                                    />
                                </dd>
                            </div>
                        </dl>
                        {nginx.config_output && (
                            <pre
                                className={`overflow-x-auto rounded-field border p-3 text-xs ${nginx.config_ok ? 'border-base-300' : 'border-error'}`}
                            >
                                {nginx.config_output}
                            </pre>
                        )}
                    </Card>

                    <Card
                        title={t('Sites managed by CommyPanel')}
                        description={t(
                            "One per app with a live release, plus the panel's own domain. Written by the agent and checked with nginx -t before every change.",
                        )}
                        flush
                    >
                        {nginx.sites.length === 0 ? (
                            <p className="p-5 text-sm text-base-content/70 sm:p-6">
                                {t(
                                    'No sites yet. An app gets one when it has a domain and a live release.',
                                )}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="table">
                                    <thead>
                                        <tr>
                                            <th>{t('Site')}</th>
                                            <th>{t('Domains')}</th>
                                            <th>HTTPS</th>
                                            <th>{t('Updated')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {nginx.sites.map((site) => (
                                            <tr key={site.name}>
                                                <td>
                                                    <code>{site.name}</code>
                                                </td>
                                                <td className="break-all">
                                                    {site.domains.join(', ')}
                                                </td>
                                                <td>
                                                    <StateMark
                                                        tone={
                                                            site.https
                                                                ? 'good'
                                                                : 'idle'
                                                        }
                                                        label={
                                                            site.https
                                                                ? 'HTTPS'
                                                                : t('HTTP only')
                                                        }
                                                    />
                                                </td>
                                                <td className="whitespace-nowrap">
                                                    {formatDate(
                                                        new Date(
                                                            site.modified *
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
        </ServerLayout>
    );
}
