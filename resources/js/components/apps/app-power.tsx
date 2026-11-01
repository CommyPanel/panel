import { router } from '@inertiajs/react';
import { ChevronDown, Play, RotateCw, Square } from 'lucide-react';
import { useState } from 'react';
import AppStateController from '@/actions/App/Http/Controllers/AppStateController';
import { Button } from '@/components/ui/button';
import { ConfirmModal } from '@/components/ui/confirm-modal';
import { Dropdown, DropdownItem } from '@/components/ui/dropdown';
import type { AppSettings } from '@/types';

type Action = 'stop' | 'restart';

const CONFIRM: Record<Action, { label: string; text: string }> = {
    restart: {
        label: 'Restart',
        text: 'The container stops and starts again. Visitors get an error for a few seconds while it restarts.',
    },
    stop: {
        label: 'Stop',
        text: 'The container stops. Its domains stay set up but show an error until you start it again. Nothing is deleted.',
    },
};

/**
 * Start, stop and restart for the live release. Only shown while the app has
 * a live release (running or stopped).
 */
export function AppPower({ app }: { app: AppSettings }) {
    const [confirm, setConfirm] = useState<Action | null>(null);
    const [busy, setBusy] = useState(false);

    const post = (action: Action | 'start') =>
        router.post(
            AppStateController[action](app.slug),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setConfirm(null);
                },
            },
        );

    if (app.status === 'stopped') {
        return (
            <Button variant="soft" onClick={() => post('start')} loading={busy}>
                <Play className="size-4" aria-hidden />
                Start
            </Button>
        );
    }

    if (app.status !== 'running') return null;

    return (
        <>
            <Dropdown
                label="Container actions"
                triggerClassName="btn-soft"
                trigger={
                    <>
                        <RotateCw className="size-4" aria-hidden />
                        Container
                        <ChevronDown className="size-4" aria-hidden />
                    </>
                }
            >
                {(close) => (
                    <>
                        <DropdownItem
                            onSelect={() => {
                                close();
                                setConfirm('restart');
                            }}
                        >
                            <RotateCw className="size-4" aria-hidden />
                            Restart
                        </DropdownItem>
                        <DropdownItem
                            tone="danger"
                            onSelect={() => {
                                close();
                                setConfirm('stop');
                            }}
                        >
                            <Square className="size-4" aria-hidden />
                            Stop
                        </DropdownItem>
                    </>
                )}
            </Dropdown>
            <ConfirmModal
                open={confirm !== null}
                title={confirm ? `${CONFIRM[confirm].label} ${app.name}?` : ''}
                confirmLabel={confirm ? CONFIRM[confirm].label : ''}
                loading={busy}
                onConfirm={() => confirm && post(confirm)}
                onClose={() => setConfirm(null)}
            >
                {confirm && CONFIRM[confirm].text}
            </ConfirmModal>
        </>
    );
}
