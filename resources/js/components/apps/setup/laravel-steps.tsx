import { router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppSetupController from '@/actions/App/Http/Controllers/AppSetupController';
import { EnvEditorCard } from '@/components/apps/env-editor-card';
import { ServicesCard } from '@/components/apps/services-card';
import { DatabaseStep } from '@/components/apps/setup/database-step';
import { RedisStep } from '@/components/apps/setup/redis-step';
import {
    AddVolumeModal,
    RemoveVolumeModal,
} from '@/components/apps/volume-dialogs';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import type { AppSetup, AppVolume, LaravelSetup } from '@/types';

/** While the code is being looked at, ask again until the answer is there. */
export function useInspection(laravel: LaravelSetup | null) {
    const inspecting = laravel?.inspecting ?? false;

    useEffect(() => {
        if (!inspecting) return;

        const timer = setInterval(
            () => router.reload({ only: ['laravel', 'app'] }),
            2000,
        );

        return () => clearInterval(timer);
    }, [inspecting]);
}

export type LaravelOptions = {
    php: string;
    node_manager: string;
};

/** The Laravel choices on the settings step: what runs the app and how its assets are built. */
export function LaravelSettingsFields({
    laravel,
    value,
    onChange,
}: {
    laravel: LaravelSetup;
    value: LaravelOptions;
    onChange: (value: LaravelOptions) => void;
}) {
    const { t } = useT();
    const detected = laravel.detected;

    return (
        <Card
            title={t('Laravel')}
            description={t(
                'CommyPanel builds a Laravel image for you: Composer packages, your assets, and a web server that is ready for production.',
            )}
        >
            {laravel.inspecting && (
                <Alert variant="info">
                    {t(
                        'Looking at your code to find the Laravel version, the PHP version and the services it may need...',
                    )}
                </Alert>
            )}
            {detected?.error && <Alert variant="error">{detected.error}</Alert>}
            {detected && detected.stack === null && !detected.error && (
                <Alert variant="warning">
                    {t(
                        'This does not look like a Laravel project (no composer.json that requires laravel/framework, or no artisan file). You can still continue with the choices below.',
                    )}
                </Alert>
            )}
            {detected?.laravel_major && (
                <p className="text-sm text-base-content/70">
                    {t('Found Laravel :major.', {
                        major: String(detected.laravel_major),
                    })}
                </p>
            )}
            <Select
                label={t('PHP version')}
                value={value.php}
                onChange={(e) => onChange({ ...value, php: e.target.value })}
                hint={t(
                    'Taken from your composer.json when it names one. The newest versions your packages allow are a good choice.',
                )}
            >
                {['8.2', '8.3', '8.4', '8.5'].map((version) => (
                    <option key={version} value={version}>
                        {version}
                    </option>
                ))}
            </Select>
            <Select
                label={t('Build your assets with')}
                value={value.node_manager}
                onChange={(e) =>
                    onChange({ ...value, node_manager: e.target.value })
                }
                hint={t(
                    'Runs your build script (Vite or Mix) while the image is built. Chosen from your lock file when there is one.',
                )}
            >
                <option value="npm">npm</option>
                <option value="pnpm">pnpm</option>
                <option value="yarn">yarn</option>
                <option value="none">{t('Do not build assets')}</option>
            </Select>
        </Card>
    );
}

function Nav({
    app,
    onContinue,
    continueLabel,
    disabled,
    busy,
}: {
    app: AppSetup;
    onContinue: () => void;
    continueLabel?: string;
    disabled?: boolean;
    busy?: boolean;
}) {
    const { t } = useT();
    const { errors } = usePage().props;

    return (
        <div className="space-y-3">
            {errors.setup && <Alert variant="error">{errors.setup}</Alert>}
            <div className="flex flex-wrap gap-2">
                <Button onClick={onContinue} loading={busy} disabled={disabled}>
                    {continueLabel ?? t('Continue')}
                </Button>
                <Button
                    variant="ghost"
                    onClick={() =>
                        router.post(AppSetupController.back(app.slug))
                    }
                >
                    {t('Back')}
                </Button>
                <Button
                    variant="ghost"
                    onClick={() => {
                        if (confirm(t('Cancel setup and delete this draft?'))) {
                            router.delete(AppSetupController.cancel(app.slug));
                        }
                    }}
                >
                    {t('Cancel setup')}
                </Button>
            </div>
        </div>
    );
}

export function LaravelDatabasePage({ app }: { app: AppSetup }) {
    const { t } = useT();
    const [state, setState] = useState({ done: false, pending: false });
    const [busy, setBusy] = useState(false);

    return (
        <div className="space-y-6">
            <Card
                title={t('Database')}
                description={t(
                    'Where your app keeps its data. It is linked to the app, and the connection arrives as the DB_ variables Laravel reads.',
                )}
            >
                <DatabaseStep slug={app.slug} onChange={setState} />
            </Card>
            <Nav
                app={app}
                disabled={!state.done || state.pending}
                busy={busy}
                onContinue={() =>
                    router.post(
                        AppSetupController.databaseContinue(app.slug),
                        {},
                        {
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                        },
                    )
                }
            />
        </div>
    );
}

export function LaravelServicesPage({
    app,
    laravel,
}: {
    app: AppSetup;
    laravel: LaravelSetup | null;
}) {
    const { t } = useT();
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);
    const redis = laravel?.redis ?? null;
    const [redisDone, setRedisDone] = useState(
        redis === null || redis.choice.mode !== null,
    );
    const horizonOn = app.processes.some(
        (process) => process.kind === 'horizon' && process.enabled,
    );

    return (
        <div className="space-y-6">
            <div className="space-y-1">
                <h2 className="text-lg font-semibold">{t('Services')}</h2>
                <p className="text-sm text-base-content/70">
                    {t(
                        'The scheduler runs your scheduled tasks and is on by default. Switch on the others your app needs and change their commands if you like. The web server always runs.',
                    )}
                </p>
            </div>
            <ServicesCard
                slug={app.slug}
                processes={app.processes}
                variant="wizard"
                onSaved={() => setDirty(false)}
                onDirtyChange={setDirty}
            />
            {redis && (
                <RedisStep
                    slug={app.slug}
                    redis={redis}
                    horizonOn={horizonOn}
                    onChange={({ done }) => setRedisDone(done)}
                />
            )}
            <Nav
                app={app}
                busy={busy}
                disabled={dirty || (redis?.suggested === true && !redisDone)}
                onContinue={() =>
                    router.post(
                        AppSetupController.servicesContinue(app.slug),
                        {},
                        {
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                        },
                    )
                }
            />
            {dirty && (
                <p className="text-sm text-warning">
                    {t('Save your changes to the services before you go on.')}
                </p>
            )}
        </div>
    );
}

export function LaravelVolumesPage({ app }: { app: AppSetup }) {
    const { t } = useT();
    const [busy, setBusy] = useState(false);
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<AppVolume | null>(null);

    return (
        <div className="space-y-6">
            <Card
                title={t('Volumes')}
                description={t(
                    'Folders that keep their files between releases. Everything else inside the container is replaced by each release. The storage folder holds uploads, logs and caches, so it is suggested for you.',
                )}
                actions={
                    <Button
                        variant="soft"
                        size="sm"
                        onClick={() => setAdding(true)}
                    >
                        <Plus className="size-4" aria-hidden />
                        {t('Add volume')}
                    </Button>
                }
            >
                {app.volumes.length === 0 ? (
                    <p className="text-sm text-base-content/70">
                        {t(
                            'No volumes. Uploaded files and logs are lost with every release. Add the storage folder if your app writes there.',
                        )}
                    </p>
                ) : (
                    <ul className="divide-y divide-base-300">
                        {app.volumes.map((volume) => (
                            <li
                                key={volume.id}
                                className="flex items-center justify-between gap-3 py-2"
                            >
                                <code className="text-sm break-all">
                                    {volume.path}
                                </code>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={t('Remove :path', {
                                        path: volume.path,
                                    })}
                                    onClick={() => setRemoving(volume)}
                                >
                                    <Trash2 className="size-4" aria-hidden />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-sm text-base-content/70">
                    {t(
                        'Your variables live on the next steps, not in a .env file, so there is no file to keep.',
                    )}
                </p>
            </Card>
            <AddVolumeModal
                slug={app.slug}
                open={adding}
                onClose={() => setAdding(false)}
            />
            <RemoveVolumeModal
                slug={app.slug}
                volume={removing}
                onClose={() => setRemoving(null)}
            />
            <Nav
                app={app}
                busy={busy}
                onContinue={() =>
                    router.post(
                        AppSetupController.volumesContinue(app.slug),
                        {},
                        {
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                        },
                    )
                }
            />
        </div>
    );
}

export function LaravelReviewPage({
    app,
    laravel,
}: {
    app: AppSetup;
    laravel: LaravelSetup;
}) {
    const { t } = useT();
    const review = laravel.review;
    const [script, setScript] = useState(app.release_script ?? '');
    const [busy, setBusy] = useState(false);

    if (review === null) return null;

    const database =
        review.database.mode === 'none' || review.database.mode === null
            ? t('No database')
            : review.database.linked
              ? `${review.database.linked.database} (${review.database.linked.engine}, ${review.database.linked.server})`
              : (review.database.database ?? t('A new database'));

    const rows: [string, string][] = [
        [t('PHP'), laravel.stack_options.php ?? laravel.detected?.php ?? '8.4'],
        [t('Database'), database],
        [
            t('Services'),
            [t('web server'), ...review.processes.map((p) => p.name)].join(
                ', ',
            ),
        ],
        [t('Volumes'), review.volumes.join(', ') || t('none')],
        [t('Domains'), review.domains.join(', ') || t('none yet')],
    ];

    return (
        <div className="space-y-6">
            <Card
                title={t('Review')}
                description={t(
                    'The last look before CommyPanel builds your app. You can change all of this later.',
                )}
            >
                <dl className="divide-y divide-base-300 text-sm">
                    {rows.map(([label, value]) => (
                        <div
                            key={label}
                            className="flex justify-between gap-4 py-2"
                        >
                            <dt className="text-base-content/70">{label}</dt>
                            <dd className="text-right font-medium break-all">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </Card>
            <Card
                title={t('Release script')}
                description={t(
                    'Runs before every release goes live, in the new image with your variables, volumes and database. One command per line; any shell syntax works. If a line fails, nothing changes and the old release keeps serving.',
                )}
            >
                <textarea
                    aria-label={t('Release script')}
                    rows={6}
                    className="textarea w-full font-mono text-sm"
                    value={script}
                    onChange={(e) => setScript(e.target.value)}
                    spellCheck={false}
                    autoComplete="off"
                />
            </Card>
            <EnvEditorCard
                slug={app.slug}
                count={review.env_count}
                linkedKeys={review.linked_keys}
            />
            <Nav
                app={app}
                busy={busy}
                continueLabel={t('Create and deploy')}
                onContinue={() =>
                    router.post(
                        AppSetupController.finish(app.slug),
                        { release_script: script },
                        {
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                        },
                    )
                }
            />
        </div>
    );
}
