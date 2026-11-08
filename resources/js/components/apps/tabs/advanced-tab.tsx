import { router } from '@inertiajs/react';
import { Lock, LockOpen, Play, Power, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AppLockController from '@/actions/App/Http/Controllers/AppLockController';
import AppStateController from '@/actions/App/Http/Controllers/AppStateController';
import { DeleteAppModal } from '@/components/apps/delete-app-modal';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { Tooltip } from '@/components/ui/tooltip';
import { useT } from '@/hooks/use-t';
import type { AppSettings } from '@/types';

export function AdvancedTab({ app }: { app: AppSettings }) {
    const { t } = useT();
    const [confirmStop, setConfirmStop] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [busy, setBusy] = useState(false);

    const setLocked = (locked: boolean) =>
        router.put(
            AppLockController.app(app.slug),
            { locked },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    const running = ['running', 'stopped'].includes(app.status);
    const stopped = app.status === 'deactivated';

    const post = (action: ReturnType<typeof AppStateController.activate>) =>
        router.post(
            action,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setConfirmStop(false);
                },
            },
        );

    return (
        <div className="space-y-6">
            {(running || stopped) && (
                <Card
                    title={
                        stopped ? t('App is deactivated') : t('Deactivate app')
                    }
                    description={
                        stopped
                            ? t(
                                  'Release #:number is stopped and its domains do not serve anything. Nothing was deleted.',
                                  { number: app.deactivated_release ?? '?' },
                              )
                            : t(
                                  'Stops the container and takes the domains offline. Releases, domains, certificates and variables are kept, so you can start it again at any time.',
                              )
                    }
                    actions={
                        stopped ? (
                            <Button
                                onClick={() =>
                                    post(AppStateController.activate(app.slug))
                                }
                                loading={busy}
                            >
                                <Play className="size-4" aria-hidden />
                                {t('Activate')}
                            </Button>
                        ) : (
                            <Button
                                variant="warning"
                                onClick={() => setConfirmStop(true)}
                            >
                                <Power className="size-4" aria-hidden />
                                {t('Deactivate')}
                            </Button>
                        )
                    }
                />
            )}

            <Card
                title={app.locked ? t('App is locked') : t('Lock app')}
                description={t(
                    "A locked app cannot be deleted, and neither can its volumes' files with it. Locking is a safety catch against a slip, such as deleting the wrong app: it does not stop deployments, restarts or any other change, and anyone who may change the app can unlock it. Locking and unlocking are recorded in the audit log.",
                )}
                actions={
                    <Button
                        variant={app.locked ? 'soft' : 'primary'}
                        onClick={() => setLocked(!app.locked)}
                        loading={busy}
                    >
                        {app.locked ? (
                            <LockOpen className="size-4" aria-hidden />
                        ) : (
                            <Lock className="size-4" aria-hidden />
                        )}
                        {app.locked ? t('Unlock') : t('Lock')}
                    </Button>
                }
            />

            <Card
                // The disabled button's tooltip opens above it and must not be cut off by the card.
                className="overflow-visible"
                title={t('Delete app')}
                description={t(
                    'Stops the app and permanently removes its containers, images, domains, certificate, variables, uploaded files and the files in its persistent volumes. This cannot be undone.',
                )}
                actions={
                    app.locked ? (
                        // aria-disabled, not disabled: the button stays focusable so the reason can be read.
                        <Tooltip
                            side="top"
                            label={t(
                                'App is locked and cannot be deleted. Unlock it first.',
                            )}
                        >
                            <Button
                                variant="error"
                                aria-disabled="true"
                                className="cursor-not-allowed opacity-50"
                                onClick={(event) => event.preventDefault()}
                            >
                                <Trash2 className="size-4" aria-hidden />
                                {t('Delete app')}
                            </Button>
                        </Tooltip>
                    ) : (
                        <Button
                            variant="error"
                            onClick={() => setConfirmDelete(true)}
                        >
                            <Trash2 className="size-4" aria-hidden />
                            {t('Delete app')}
                        </Button>
                    )
                }
            />

            <ConfirmModal
                open={confirmStop}
                title={t('Deactivate :name?', { name: app.name })}
                confirmLabel={t('Deactivate')}
                loading={busy}
                onConfirm={() => post(AppStateController.deactivate(app.slug))}
                onClose={() => setConfirmStop(false)}
            >
                {t(
                    'The app goes offline right away. You can activate it again from this tab.',
                )}
            </ConfirmModal>
            <DeleteAppModal
                slug={app.slug}
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
            />
        </div>
    );
}
