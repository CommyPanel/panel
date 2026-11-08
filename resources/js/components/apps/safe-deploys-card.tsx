import { router, usePage } from '@inertiajs/react';
import {
    Gauge,
    Plus,
    ShieldCheck,
    Split,
    Timer,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import AppSafeDeployController from '@/actions/App/Http/Controllers/AppSafeDeployController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { useT } from '@/hooks/use-t';
import type { SafeDeploys } from '@/types';

const MAX_STEPS = 4;

function Section({
    icon,
    title,
    children,
}: {
    icon: ReactNode;
    title: string;
    children: ReactNode;
}) {
    return (
        <div className="flex gap-4">
            <div className="flex size-11 shrink-0 items-center justify-center rounded-box bg-primary/10 text-primary">
                {icon}
            </div>
            <div className="min-w-0 flex-1 space-y-4">
                <h3 className="text-base font-semibold">{title}</h3>
                {children}
            </div>
        </div>
    );
}

function Switch({
    checked,
    onChange,
    label,
    description,
}: {
    checked: boolean;
    onChange: (next: boolean) => void;
    label: string;
    description: ReactNode;
}) {
    return (
        <label className="flex cursor-pointer items-start gap-3">
            <input
                type="checkbox"
                role="switch"
                className="switch mt-0.5 switch-primary"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
            />
            <span className="text-sm">
                <span className="font-medium">{label}</span>
                <span className="block text-base-content/70">
                    {description}
                </span>
            </span>
        </label>
    );
}

/**
 * Watching new releases (on by default) and gradual rollouts (off by default). Turning a
 * rollout on opens a dialog that says what it asks of the app, and has to be confirmed.
 */
export function SafeDeploysCard({
    slug,
    settings,
}: {
    slug: string;
    settings: SafeDeploys;
}) {
    const { t } = useT();
    const { errors } = usePage().props;
    const [data, setData] = useState<SafeDeploys>(settings);
    const [understood, setUnderstood] = useState(false);
    const [asking, setAsking] = useState(false);
    const [agreed, setAgreed] = useState(false);
    const [saving, setSaving] = useState(false);

    const set = <K extends keyof SafeDeploys>(key: K, value: SafeDeploys[K]) =>
        setData((d) => ({ ...d, [key]: value }));

    const setStep = (index: number, value: string) =>
        set(
            'rollout_steps',
            data.rollout_steps.map((s, i) => (i === index ? Number(value) : s)),
        );

    const save = () =>
        router.put(
            AppSafeDeployController.update(slug).url,
            { ...data, understood },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setUnderstood(false),
            },
        );

    const turnRolloutOn = (next: boolean) => {
        if (next && !settings.rollout_enabled) {
            setAgreed(false);
            setAsking(true);

            return;
        }

        set('rollout_enabled', next);
    };

    const confirmRollout = () => {
        set('rollout_enabled', true);
        setUnderstood(true);
        setAsking(false);
    };

    const path = `${data.rollout_steps.join('% → ')}% → 100%`;
    const error = (key: string) => (errors as Record<string, string>)[key];

    return (
        <Card
            title={t('Safe deployments')}
            description={t(
                'Keep a bad release from hurting your visitors: CommyPanel watches each new release and can roll it out gradually.',
            )}
        >
            <div className="space-y-8">
                <Section
                    icon={<ShieldCheck className="size-6" aria-hidden />}
                    title={t('Watch new releases')}
                >
                    <Switch
                        checked={data.watch_enabled}
                        onChange={(v) => set('watch_enabled', v)}
                        label={t('Roll back by itself if a release does worse')}
                        description={t(
                            'After a release goes live, the previous one keeps running for a few minutes. If the new release fails clearly more requests (5xx) or is much slower than the one before it, or its container stops, traffic goes straight back to the previous release and you get a message. You can always try the release again by hand.',
                        )}
                    />
                    {data.watch_enabled && (
                        <Input
                            label={t('Watch for (minutes)')}
                            type="number"
                            min={1}
                            max={60}
                            value={data.watch_minutes}
                            onChange={(e) =>
                                set('watch_minutes', Number(e.target.value))
                            }
                            error={error('watch_minutes')}
                            hint={t(
                                'The previous container uses memory until the watch ends. It needs enough requests to judge: an app with almost no traffic is simply accepted.',
                            )}
                        />
                    )}
                </Section>

                <Section
                    icon={<Split className="size-6" aria-hidden />}
                    title={t('Gradual rollout')}
                >
                    <Switch
                        checked={data.rollout_enabled}
                        onChange={turnRolloutOn}
                        label={t('Roll new releases out in steps')}
                        description={t(
                            'Instead of switching everyone at once, give the new release a growing share of the visitors (for example :path) and look at the requests at each step. For busy apps only. Off unless you turn it on.',
                            { path },
                        )}
                    />
                    {data.rollout_enabled && (
                        <div className="space-y-4">
                            <fieldset className="space-y-2">
                                <legend className="mb-1 label-text">
                                    {t('Steps (share of visitors)')}
                                </legend>
                                <div className="flex flex-wrap items-center gap-2">
                                    {data.rollout_steps.map((step, index) => (
                                        <div
                                            key={index}
                                            className="flex items-center gap-1 rounded-field border border-base-300 pr-1 pl-2"
                                        >
                                            <input
                                                aria-label={t(
                                                    'Step :number percent',
                                                    {
                                                        number: index + 1,
                                                    },
                                                )}
                                                type="number"
                                                min={1}
                                                max={99}
                                                className="w-14 bg-transparent py-1.5 text-sm outline-none"
                                                value={step}
                                                onChange={(e) =>
                                                    setStep(
                                                        index,
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <span className="text-sm text-base-content/70">
                                                %
                                            </span>
                                            {data.rollout_steps.length > 1 && (
                                                <button
                                                    type="button"
                                                    className="btn btn-circle btn-text btn-xs"
                                                    aria-label={t(
                                                        'Remove step :number',
                                                        {
                                                            number: index + 1,
                                                        },
                                                    )}
                                                    onClick={() =>
                                                        set(
                                                            'rollout_steps',
                                                            data.rollout_steps.filter(
                                                                (_, i) =>
                                                                    i !== index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <X
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                </button>
                                            )}
                                        </div>
                                    ))}
                                    {data.rollout_steps.length < MAX_STEPS && (
                                        <Button
                                            variant="soft"
                                            size="sm"
                                            onClick={() =>
                                                set('rollout_steps', [
                                                    ...data.rollout_steps,
                                                    Math.min(
                                                        99,
                                                        (data.rollout_steps.at(
                                                            -1,
                                                        ) ?? 0) + 25,
                                                    ),
                                                ])
                                            }
                                        >
                                            <Plus
                                                className="size-4"
                                                aria-hidden
                                            />
                                            {t('Add a step')}
                                        </Button>
                                    )}
                                </div>
                                {(error('rollout_steps') ||
                                    error('rollout_steps.0') ||
                                    error('rollout_steps.1')) && (
                                    <p className="text-sm text-error">
                                        {error('rollout_steps') ??
                                            error('rollout_steps.0') ??
                                            error('rollout_steps.1')}
                                    </p>
                                )}
                                <p className="text-sm text-base-content/70">
                                    {t(
                                        'Up to four steps, each between 1 and 99, in rising order. After the last one everybody gets the release: :path.',
                                        { path },
                                    )}
                                </p>
                            </fieldset>

                            <Input
                                label={t('Hold each step for (minutes)')}
                                type="number"
                                min={1}
                                max={120}
                                value={data.rollout_hold_minutes}
                                onChange={(e) =>
                                    set(
                                        'rollout_hold_minutes',
                                        Number(e.target.value),
                                    )
                                }
                                error={error('rollout_hold_minutes')}
                                hint={t(
                                    'A step with too little traffic is held up to three times as long, then CommyPanel asks you instead of guessing.',
                                )}
                            />

                            <fieldset className="space-y-2">
                                <legend className="mb-1 label-text">
                                    {t('Moving to the next step')}
                                </legend>
                                <label className="flex cursor-pointer items-start gap-3 text-sm">
                                    <input
                                        type="radio"
                                        className="radio mt-0.5"
                                        checked={data.rollout_auto}
                                        onChange={() =>
                                            set('rollout_auto', true)
                                        }
                                    />
                                    <span>
                                        <span className="font-medium">
                                            {t(
                                                'By itself while it looks healthy',
                                            )}
                                        </span>
                                        <span className="block text-base-content/70">
                                            {t(
                                                'Rolls back by itself if the release does clearly worse.',
                                            )}
                                        </span>
                                    </span>
                                </label>
                                <label className="flex cursor-pointer items-start gap-3 text-sm">
                                    <input
                                        type="radio"
                                        className="radio mt-0.5"
                                        checked={!data.rollout_auto}
                                        onChange={() =>
                                            set('rollout_auto', false)
                                        }
                                    />
                                    <span>
                                        <span className="font-medium">
                                            {t('Wait for me after each step')}
                                        </span>
                                        <span className="block text-base-content/70">
                                            {t(
                                                'You press Move on (or Abort). It still rolls back by itself if the release does clearly worse.',
                                            )}
                                        </span>
                                    </span>
                                </label>
                            </fieldset>
                        </div>
                    )}
                    {error('understood') && (
                        <Alert variant="error">{error('understood')}</Alert>
                    )}
                </Section>

                <div className="flex justify-end">
                    <Button onClick={save} loading={saving}>
                        {t('Save')}
                    </Button>
                </div>
            </div>

            <Modal
                open={asking}
                onClose={() => setAsking(false)}
                title={t('Before you turn on gradual rollouts')}
                footer={
                    <>
                        <Button
                            variant="ghost"
                            onClick={() => setAsking(false)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button onClick={confirmRollout} disabled={!agreed}>
                            {t('Turn on')}
                        </Button>
                    </>
                }
            >
                <div className="space-y-4 text-sm">
                    <p>
                        {t(
                            'A gradual rollout is a power tool. It only makes sense for an app with a lot of traffic, and it asks a few things of your app:',
                        )}
                    </p>
                    <ul className="space-y-3">
                        {[
                            [
                                TriangleAlert,
                                t('Two versions run side by side.'),
                                t(
                                    'For the whole rollout (steps × hold time), the old and the new release both serve visitors and share the same database and volumes. A migration must work with both: add first, remove later.',
                                ),
                            ],
                            [
                                Gauge,
                                t('It needs enough requests to judge.'),
                                t(
                                    'A step of 10% on a quiet app may see too few requests to say anything. CommyPanel then waits longer and finally asks you, instead of guessing.',
                                ),
                            ],
                            [
                                Timer,
                                t('A deployment takes longer.'),
                                t(
                                    'A new release only goes live after its last step. New deployments wait until it ends, and you can move on, give everyone the release, or abort at any time.',
                                ),
                            ],
                            [
                                Split,
                                t('Visitors stay on one version.'),
                                t(
                                    'A cookie keeps each visitor on one release. A client that refuses cookies can switch between versions. Long requests and open connections stay on the version they started on.',
                                ),
                            ],
                        ].map(([Icon, head, text]) => {
                            const I = Icon as typeof Gauge;

                            return (
                                <li key={head as string} className="flex gap-3">
                                    <I
                                        className="mt-0.5 size-5 shrink-0 text-warning"
                                        aria-hidden
                                    />
                                    <span>
                                        <strong>{head as string}</strong>{' '}
                                        <span className="text-base-content/80">
                                            {text as string}
                                        </span>
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                    <p className="text-base-content/70">
                        {t(
                            'It also uses memory for two containers while it runs, and it needs the app to have a domain. Rollbacks and applying changes stay instant: they are never gradual.',
                        )}
                    </p>
                    <label className="flex cursor-pointer items-start gap-3 rounded-field border border-base-300 p-3">
                        <input
                            type="checkbox"
                            className="checkbox mt-0.5"
                            checked={agreed}
                            onChange={(e) => setAgreed(e.target.checked)}
                        />
                        <span>
                            {t(
                                'I understand what a gradual rollout asks of my app.',
                            )}
                        </span>
                    </label>
                </div>
            </Modal>
        </Card>
    );
}
