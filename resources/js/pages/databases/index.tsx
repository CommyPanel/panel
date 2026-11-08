import { Link, usePage } from '@inertiajs/react';
import { Database, Plus } from 'lucide-react';
import { useState } from 'react';
import DatabaseServerController from '@/actions/App/Http/Controllers/DatabaseServerController';
import { AddServerModal } from '@/components/databases/add-server-modal';
import { DatabaseStatusBadge } from '@/components/databases/database-status';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/lib/permissions';
import { Trans } from '@/lib/trans';
import type { DatabaseServer, EngineChoice } from '@/types';

type Props = { servers: DatabaseServer[]; engines: EngineChoice[] };

export default function Index({ servers, engines }: Props) {
    const { t } = useT();
    const can = useCan();
    const { errors } = usePage().props;
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout title={t('Databases')}>
            <PageHeader
                title={t('Databases')}
                description={t(
                    'MariaDB, MySQL and PostgreSQL servers that run on this server, for your apps.',
                )}
                actions={
                    can('databases.manage') && (
                        <Button onClick={() => setAdding(true)}>
                            <Plus className="size-4" aria-hidden />
                            {t('Add server')}
                        </Button>
                    )
                }
            />

            {errors.database && (
                <Alert variant="error">{errors.database}</Alert>
            )}

            {servers.length === 0 ? (
                <Card
                    title={t('No database server yet')}
                    description={t(
                        'An app that needs a database stores it in a database server. Add one, create a database on it, then link the database to your app.',
                    )}
                >
                    <ol className="list-decimal space-y-1.5 pl-5 text-sm text-base-content/80">
                        <li>
                            {t('Add a server (MariaDB, MySQL or PostgreSQL).')}
                        </li>
                        <li>{t('Create a database on it.')}</li>
                        <li>
                            <Trans
                                text="Open your app, go to its <b>Links</b> tab and link the database. The app gets the connection as environment variables, such as <code>DATABASE_URL</code>, after its next deployment."
                                components={{ b: <strong />, code: <code /> }}
                            />
                        </li>
                    </ol>
                </Card>
            ) : (
                <Card title={t('Servers')} flush>
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>{t('Name')}</th>
                                    <th>{t('Database')}</th>
                                    <th>{t('Status')}</th>
                                    <th className="text-right">
                                        {t('Databases')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {servers.map((server) => (
                                    <tr key={server.slug}>
                                        <td>
                                            <Link
                                                href={DatabaseServerController.show(
                                                    server.slug,
                                                )}
                                                className="link inline-flex items-center gap-2 font-medium link-primary"
                                            >
                                                <Database
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                                {server.name}
                                            </Link>
                                        </td>
                                        <td>
                                            {server.engine_label}{' '}
                                            {server.version}
                                        </td>
                                        <td>
                                            <DatabaseStatusBadge
                                                status={server.status}
                                            />
                                        </td>
                                        <td className="text-right">
                                            {server.databases_count}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}

            <AddServerModal
                open={adding}
                onClose={() => setAdding(false)}
                engines={engines}
            />
        </AppLayout>
    );
}
