import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import UserSessionController from '@/actions/App/Http/Controllers/UserSessionController';
import { PageHeader } from '@/components/page-header';
import { SessionList } from '@/components/sessions/session-list';
import type { SessionRow } from '@/components/sessions/session-list';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';

type Props = {
    target: { id: number; name: string; email: string };
    sessions: { supported: boolean; list: SessionRow[] };
    can_revoke: boolean;
};

export default function UserSessions({ target, sessions, can_revoke }: Props) {
    const { t } = useT();
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();

    const revoke = (handle: string) =>
        confirmed.run(() =>
            router.delete(UserSessionController.destroy([target.id, handle]), {
                preserveScroll: true,
            }),
        );

    const revokeAll = () =>
        confirmed.run(() =>
            router.delete(UserSessionController.destroyAll(target.id), {
                preserveScroll: true,
            }),
        );

    return (
        <AppLayout title={t('Sessions of :name', { name: target.name })}>
            <PageHeader
                title={t('Sessions of :name', { name: target.name })}
                description={target.email}
                actions={
                    <Link
                        href={UserController.index()}
                        className="btn btn-text"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        {t('Back to users')}
                    </Link>
                }
            />
            {errors.session && <Alert variant="error">{errors.session}</Alert>}
            <Card
                title={t('Active sessions')}
                description={t(
                    'Where this person is signed in. Looking at this list is recorded in the audit log.',
                )}
            >
                {sessions.supported ? (
                    <div className="space-y-4">
                        <SessionList
                            sessions={sessions.list}
                            onRevoke={can_revoke ? revoke : undefined}
                        />
                        {can_revoke && sessions.list.length > 0 && (
                            <Button
                                variant="error"
                                size="sm"
                                onClick={revokeAll}
                            >
                                {t('Sign out everywhere')}
                            </Button>
                        )}
                    </div>
                ) : (
                    <p className="text-sm text-base-content/70">
                        {t(
                            'The session list needs the database session driver, which this installation does not use.',
                        )}
                    </p>
                )}
            </Card>
            {confirmed.modal}
        </AppLayout>
    );
}
