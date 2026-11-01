import { router } from '@inertiajs/react';
import { Play, Power, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AppStateController from '@/actions/App/Http/Controllers/AppStateController';
import { DeleteAppModal } from '@/components/apps/delete-app-modal';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import type { AppSettings } from '@/types';

export function AdvancedTab({ app }: { app: AppSettings }) {
    const [confirmStop, setConfirmStop] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [busy, setBusy] = useState(false);

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
        <div className="max-w-3xl space-y-6">
            {(running || stopped) && (
                <Card
                    title={stopped ? 'App is deactivated' : 'Deactivate app'}
                    description={
                        stopped
                            ? `Release #${app.deactivated_release ?? '?'} is stopped and its domains do not serve anything. Nothing was deleted.`
                            : 'Stops the container and takes the domains offline. Releases, domains, certificates and variables are kept, so you can start it again at any time.'
                    }
                >
                    {stopped ? (
                        <div>
                            <Button
                                onClick={() =>
                                    post(AppStateController.activate(app.slug))
                                }
                                loading={busy}
                            >
                                <Play className="size-4" aria-hidden />
                                Activate
                            </Button>
                        </div>
                    ) : (
                        <div>
                            <Button
                                variant="warning"
                                onClick={() => setConfirmStop(true)}
                            >
                                <Power className="size-4" aria-hidden />
                                Deactivate
                            </Button>
                        </div>
                    )}
                </Card>
            )}

            <Card
                title="Delete app"
                description="Stops the app and permanently removes its containers, images, domains, certificate, variables and uploaded files. This cannot be undone."
            >
                <div>
                    <Button
                        variant="error"
                        onClick={() => setConfirmDelete(true)}
                    >
                        <Trash2 className="size-4" aria-hidden />
                        Delete app
                    </Button>
                </div>
            </Card>

            <ConfirmModal
                open={confirmStop}
                title={`Deactivate ${app.name}?`}
                confirmLabel="Deactivate"
                loading={busy}
                onConfirm={() => post(AppStateController.deactivate(app.slug))}
                onClose={() => setConfirmStop(false)}
            >
                The app goes offline right away. You can activate it again from
                this tab.
            </ConfirmModal>
            <DeleteAppModal
                slug={app.slug}
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
            />
        </div>
    );
}
