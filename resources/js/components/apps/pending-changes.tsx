import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import DeploymentController from '@/actions/App/Http/Controllers/DeploymentController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { useCan } from '@/lib/permissions';

/**
 * Variables, volumes and links reach an app only when its container is made, so after a change
 * the running one is out of date. "Apply changes" makes a new container from the image that is
 * already live (no build) and switches over as a deployment does, so nothing goes down.
 */
export function PendingChanges({ slug }: { slug: string }) {
    const can = useCan();
    const [asking, setAsking] = useState(false);
    const [busy, setBusy] = useState(false);

    const apply = () =>
        router.post(
            DeploymentController.apply(slug),
            {},
            {
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setAsking(false);
                },
            },
        );

    return (
        <Alert variant="warning">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <span>
                    Variables, volumes or links changed. The running version
                    does not have them yet.
                </span>
                {can('apps.deploy') && (
                    <Button size="sm" onClick={() => setAsking(true)}>
                        <RefreshCw className="size-4" aria-hidden />
                        Apply changes
                    </Button>
                )}
            </div>
            <ConfirmModal
                open={asking}
                title="Apply the changes?"
                confirmLabel="Apply changes"
                loading={busy}
                onConfirm={apply}
                onClose={() => setAsking(false)}
            >
                A new container is made from the version that is running now,
                with the new variables, volumes and links, and started next to
                the old one. The old one keeps serving until the new one passes
                its health check, then the traffic moves over and the old one
                stops, so there is no downtime. For a short while both run at
                once, so the app must be fine with that. If the new container
                does not become healthy, nothing changes. Nothing is fetched or
                built, and your code is not updated.
            </ConfirmModal>
        </Alert>
    );
}
