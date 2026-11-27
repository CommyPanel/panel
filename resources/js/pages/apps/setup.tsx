import { router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Upload, XCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import AppSetupController from '@/actions/App/Http/Controllers/AppSetupController';
import DeployKeyController from '@/actions/App/Http/Controllers/DeployKeyController';
import { AccessTokenCard } from '@/components/apps/access-token-card';
import { AppDomains } from '@/components/apps/app-domains';
import type { DomainDraft } from '@/components/apps/app-domains';
import AppDomainController from '@/actions/App/Http/Controllers/AppDomainController';
import { Modal } from '@/components/ui/modal';
import {
    ReleaseCommandInput,
    ReleaseScriptInput,
} from '@/components/apps/app-fields';
import { scriptExample } from '@/components/apps/script-examples';
import {
    LaravelDatabasePage,
    LaravelReviewPage,
    LaravelServicesPage,
    LaravelSettingsFields,
    LaravelVolumesPage,
    useInspection,
} from '@/components/apps/setup/laravel-steps';
import type { LaravelOptions } from '@/components/apps/setup/laravel-steps';
import { SetupSteps } from '@/components/apps/setup-steps';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import { postJson } from '@/lib/post-json';
import type {
    AppSetup,
    LaravelSetup,
    ServerAddress,
    SourceAccess,
    DeployStrategy,
} from '@/types';

type Provider = { name: string; url: string | null; steps: string[] };
type Props = {
    app: AppSetup;
    provider: Provider | null;
    server: ServerAddress;
    limits: { max_archive_mb: number };
    /** Only for a Laravel app: what its code showed, the choices so far and the last look. */
    laravel: LaravelSetup | null;
};

export default function Setup({
    app,
    provider,
    server,
    limits,
    laravel,
}: Props) {
    const { t } = useT();

    useInspection(laravel);

    return (
        <AppLayout title={t('Set up :name', { name: app.name })}>
            <PageHeader
                title={t('Set up :name', { name: app.name })}
                description={t(
                    'A few steps, then CommyPanel builds and starts your app.',
                )}
            />
            <SetupSteps
                current={app.setup_step}
                source={app.source_type}
                laravel={laravel !== null}
            />
            {app.setup_step === 'source' ? (
                app.source_type === 'git' ? (
                    <GitStep app={app} provider={provider} />
                ) : (
                    <UploadStep app={app} maxMb={limits.max_archive_mb} />
                )
            ) : app.setup_step === 'settings' ? (
                <SettingsStep app={app} laravel={laravel} />
            ) : app.setup_step === 'database' ? (
                <LaravelDatabasePage app={app} />
            ) : app.setup_step === 'services' ? (
                <LaravelServicesPage app={app} laravel={laravel} />
            ) : app.setup_step === 'volumes' ? (
                <LaravelVolumesPage app={app} />
            ) : app.setup_step === 'review' && laravel !== null ? (
                <LaravelReviewPage app={app} laravel={laravel} />
            ) : (
                <DomainStep
                    app={app}
                    server={server}
                    laravel={laravel !== null}
                />
            )}
        </AppLayout>
    );
}

function Footer({ app, canContinue }: { app: AppSetup; canContinue: boolean }) {
    const { t } = useT();
    const [busy, setBusy] = useState(false);
    const { errors } = usePage().props;

    return (
        <div className="space-y-3">
            {errors.source && <Alert variant="error">{errors.source}</Alert>}
            <div className="flex flex-wrap gap-2">
                <Button
                    disabled={!canContinue}
                    loading={busy}
                    onClick={() =>
                        router.post(
                            AppSetupController.continueMethod(app.slug),
                            {},
                            {
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                            },
                        )
                    }
                >
                    {t('Continue')}
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

function GitStep({
    app,
    provider,
}: {
    app: AppSetup;
    provider: Provider | null;
}) {
    const { t } = useT();
    const { errors } = usePage().props;
    const [branch, setBranch] = useState(app.git_branch);
    const [access, setAccess] = useState<SourceAccess | null>(null);
    const [checking, setChecking] = useState(false);
    const [keyBusy, setKeyBusy] = useState(false);
    const [copied, setCopied] = useState(false);
    const [failed, setFailed] = useState(false);

    const check = async () => {
        setChecking(true);
        setFailed(false);
        try {
            const result = await postJson<SourceAccess>(
                AppSetupController.check(app.slug).url,
                { git_branch: branch },
            );
            setAccess(result);
            router.reload({ only: ['app', 'provider'] });
        } catch {
            setFailed(true);
        } finally {
            setChecking(false);
        }
    };

    const generateKey = () =>
        router.post(
            DeployKeyController.store(app.slug),
            {},
            {
                preserveScroll: true,
                onStart: () => setKeyBusy(true),
                onFinish: () => setKeyBusy(false),
            },
        );

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(app.deploy_key_public ?? '');
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // The key stays selectable if the clipboard is blocked.
        }
    };

    const needsKey =
        access?.state === 'key_required' ||
        app.deploy_key_public !== null ||
        app.git_token !== null;

    return (
        <div className="space-y-6">
            <Card
                title={t('2. Can CommyPanel read your repository?')}
                description={t(
                    'We test it from this server, so what you see is what the deployment will get.',
                )}
            >
                <dl className="grid gap-1 text-sm sm:grid-cols-[8rem_1fr]">
                    <dt className="text-base-content/70">{t('Repository')}</dt>
                    <dd className="min-w-0 font-mono break-all">
                        {app.git_url}
                    </dd>
                </dl>
                <Input
                    label={t('Branch')}
                    value={branch}
                    onChange={(e) => setBranch(e.target.value)}
                />
                <div>
                    <Button
                        onClick={check}
                        loading={checking}
                        variant={access?.ready ? 'soft' : 'primary'}
                    >
                        {access ? t('Check again') : t('Check access')}
                    </Button>
                </div>
                {failed && (
                    <Alert variant="error">
                        {t('The check could not run. Try again.')}
                    </Alert>
                )}
                {access && (
                    <Alert variant={access.ready ? 'success' : 'warning'}>
                        {access.ready ? (
                            <CheckCircle2 className="size-5" aria-hidden />
                        ) : (
                            <XCircle className="size-5" aria-hidden />
                        )}
                        <span>{access.message}</span>
                    </Alert>
                )}
                {!access && app.source_verified && (
                    <Alert variant="success">
                        <CheckCircle2 className="size-5" aria-hidden />
                        <span>{t('Access was verified earlier.')}</span>
                    </Alert>
                )}
            </Card>

            {needsKey && !app.source_verified && (
                <p className="text-sm text-base-content/70">
                    {t(
                        'A private repository needs a deploy key or an access token. Use whichever is easier for you.',
                    )}
                </p>
            )}

            {needsKey && !app.source_verified && (
                <Card
                    title={t('Deploy key')}
                    description={t(
                        'A private repository needs a read-only key. It is created here and never leaves this server.',
                    )}
                >
                    {errors.deploy_key && (
                        <Alert variant="error">{errors.deploy_key}</Alert>
                    )}
                    {app.deploy_key_public === null ? (
                        <div>
                            <Button
                                onClick={generateKey}
                                loading={keyBusy}
                                variant="soft"
                            >
                                <KeyRound className="size-4" aria-hidden />
                                {t('Generate deploy key')}
                            </Button>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <textarea
                                readOnly
                                rows={3}
                                aria-label={t('Public deploy key')}
                                className="textarea w-full font-mono text-xs"
                                value={app.deploy_key_public}
                                onFocus={(e) => e.currentTarget.select()}
                            />
                            <div>
                                <Button variant="soft" size="sm" onClick={copy}>
                                    {copied
                                        ? t('Copied')
                                        : t('Copy public key')}
                                </Button>
                            </div>
                            {provider && provider.steps.length > 0 && (
                                <div className="space-y-2 text-sm">
                                    <p className="font-medium">
                                        {t('Add it on :provider:', {
                                            provider: provider.name,
                                        })}
                                    </p>
                                    <ol className="list-decimal space-y-1 pl-5">
                                        {provider.steps.map((step) => (
                                            <li key={step}>{step}</li>
                                        ))}
                                    </ol>
                                    {provider.url && (
                                        <a
                                            className="link link-primary"
                                            href={provider.url}
                                            target="_blank"
                                            rel="noreferrer noopener"
                                        >
                                            {t('Open :provider key settings', {
                                                provider: provider.name,
                                            })}
                                        </a>
                                    )}
                                    <p className="text-base-content/70">
                                        {t('Then press “Check access” above.')}
                                    </p>
                                </div>
                            )}
                        </div>
                    )}
                </Card>
            )}

            {needsKey && (!app.source_verified || app.git_token !== null) && (
                <AccessTokenCard app={app} />
            )}

            <Footer app={app} canContinue={app.source_verified} />
        </div>
    );
}

function UploadStep({ app, maxMb }: { app: AppSetup; maxMb: number }) {
    const { t } = useT();
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{ archive: File | null }>({ archive: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!form.data.archive) return;
        form.post(AppSetupController.upload(app.slug).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                if (input.current) input.current.value = '';
            },
        });
    };

    return (
        <div className="space-y-6">
            <Card
                title={t('2. Upload your project')}
                description={t(
                    'A .zip or .tar.gz up to :size MB. A single top-level folder is unpacked for you; .git folders are skipped.',
                    { size: maxMb },
                )}
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="w-full">
                        <label
                            htmlFor="archive"
                            className="mb-1 label-text block px-0!"
                        >
                            {t('Project archive')}
                        </label>
                        <input
                            ref={input}
                            id="archive"
                            type="file"
                            accept=".zip,.tar.gz,.tgz,application/zip,application/gzip"
                            className="input w-full"
                            onChange={(e) =>
                                form.setData(
                                    'archive',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        {form.errors.archive && (
                            <span className="mt-1 block text-sm text-error">
                                {form.errors.archive}
                            </span>
                        )}
                    </div>
                    {form.progress && (
                        <progress
                            className="progress w-full progress-primary"
                            value={form.progress.percentage}
                            max={100}
                        />
                    )}
                    <div>
                        <Button
                            type="submit"
                            disabled={!form.data.archive}
                            loading={form.processing}
                        >
                            <Upload className="size-4" aria-hidden />
                            {app.has_upload ? t('Replace upload') : t('Upload')}
                        </Button>
                    </div>
                </form>
                {app.has_upload && app.source_verified && (
                    <Alert variant="success">
                        <CheckCircle2 className="size-5" aria-hidden />
                        <span>{t('Your project is uploaded and ready.')}</span>
                    </Alert>
                )}
            </Card>
            <Footer app={app} canContinue={app.source_verified} />
        </div>
    );
}

function SettingsStep({
    app,
    laravel,
}: {
    app: AppSetup;
    laravel: LaravelSetup | null;
}) {
    const { t } = useT();
    const [options, setOptions] = useState<LaravelOptions>({
        php: laravel?.stack_options.php ?? laravel?.detected?.php ?? '8.4',
        node_manager:
            laravel?.stack_options.node_manager ??
            laravel?.detected?.node_manager ??
            (laravel?.detected?.has_build_script ? 'npm' : 'none'),
    });
    const touched = useRef(false);
    const form = useForm({
        container_port: String(app.container_port),
        health_path: app.health_path,
        health_timeout: app.health_timeout ? String(app.health_timeout) : '',
        deploy_strategy: app.deploy_strategy,
        release_command: app.release_command ?? '',
        release_script: app.release_script ?? '',
        memory_limit_mb: app.memory_limit_mb ? String(app.memory_limit_mb) : '',
        cpu_limit: app.cpu_limit ? String(app.cpu_limit) : '',
        env: '',
    });

    // What the code showed fills the choices once, unless the person already touched them.
    const detected = laravel?.detected;

    useEffect(() => {
        if (!detected || touched.current || detected.stack === null) return;

        setOptions({
            php: detected.php ?? options.php,
            node_manager:
                detected.node_manager ??
                (detected.has_build_script ? 'npm' : 'none'),
        });

        if (app.health_path === '/' && detected.health_path) {
            form.setData('health_path', detected.health_path);
        }
    }, [detected]); // eslint-disable-line react-hooks/exhaustive-deps

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => {
            // A script replaces the single release command, so only one of them is sent.
            const { release_command: _command, ...withScript } = data;
            const sent = app.has_release_script ? withScript : data;

            return laravel ? { ...sent, stack_options: options } : sent;
        });
        form.post(AppSetupController.settings(app.slug).url);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            {laravel && (
                <LaravelSettingsFields
                    laravel={laravel}
                    value={options}
                    onChange={(value) => {
                        touched.current = true;
                        setOptions(value);
                    }}
                />
            )}
            <Card
                title={t('3. Settings')}
                description={t(
                    'You can change all of this later. Variables are encrypted and shown only by name.',
                )}
            >
                {!laravel && (
                    <div className="w-full">
                        <label
                            htmlFor="env"
                            className="mb-1 label-text block px-0!"
                        >
                            {t('Environment variables')}
                        </label>
                        <textarea
                            id="env"
                            rows={6}
                            className="textarea w-full font-mono text-sm"
                            placeholder={
                                'DATABASE_URL=postgres://...\nAPI_KEY=secret'
                            }
                            value={form.data.env}
                            onChange={(e) =>
                                form.setData('env', e.target.value)
                            }
                            spellCheck={false}
                            autoComplete="off"
                        />
                        <span className="mt-1 block text-sm text-base-content/60">
                            {t(
                                'Paste a .env file. One KEY=value per line; PORT is set for you.',
                            )}
                        </span>
                        {form.errors.env && (
                            <span className="block text-sm text-error">
                                {form.errors.env}
                            </span>
                        )}
                    </div>
                )}
                <Input
                    label={t('App port')}
                    type="number"
                    min={1}
                    max={65535}
                    required
                    value={form.data.container_port}
                    onChange={(e) =>
                        form.setData('container_port', e.target.value)
                    }
                    error={form.errors.container_port}
                    hint={t(
                        'The port your app listens on inside the container. It is also passed as PORT.',
                    )}
                />
                <Input
                    label={t('Health check path')}
                    required
                    value={form.data.health_path}
                    onChange={(e) =>
                        form.setData('health_path', e.target.value)
                    }
                    error={form.errors.health_path}
                    hint={t(
                        'A new version goes live once this path answers (anything below HTTP 500).',
                    )}
                />
                <Input
                    label={t('Health check timeout (seconds)')}
                    type="number"
                    min={10}
                    max={1800}
                    placeholder={t('Default')}
                    value={form.data.health_timeout}
                    onChange={(e) =>
                        form.setData('health_timeout', e.target.value)
                    }
                    error={form.errors.health_timeout}
                    hint={t(
                        'How long a new version gets to answer the health check before the deployment fails. Slow starters, such as big .NET apps, may need more. Empty uses the server default (60 seconds).',
                    )}
                />
                <Select
                    label={t('Deploy mode')}
                    value={form.data.deploy_strategy}
                    onChange={(e) =>
                        form.setData(
                            'deploy_strategy',
                            e.target.value as DeployStrategy,
                        )
                    }
                    error={form.errors.deploy_strategy}
                    hint={t(
                        'Zero downtime starts the new version next to the old one and switches when it answers. Stop the old version first is for software that cannot run twice on the same data (such as Redis or Elasticsearch): the app is unavailable for a short while, and if the new version does not start the old one is started again.',
                    )}
                >
                    <option value="rolling">{t('Zero downtime')}</option>
                    <option value="stop_first">
                        {t('Stop the old version first')}
                    </option>
                </Select>
                {!laravel && app.has_release_script && (
                    <ReleaseScriptInput
                        value={form.data.release_script}
                        onChange={(value) =>
                            form.setData('release_script', value)
                        }
                        error={form.errors.release_script}
                        placeholder={scriptExample(app.stack_hint ?? app.stack)}
                        minLines={6}
                        maxLines={14}
                    />
                )}
                {!laravel && !app.has_release_script && (
                    <ReleaseCommandInput
                        value={form.data.release_command}
                        onChange={(value) =>
                            form.setData('release_command', value)
                        }
                        error={form.errors.release_command}
                    />
                )}
                <Input
                    label={t('Memory limit (MB)')}
                    type="number"
                    min={16}
                    placeholder={t('Default')}
                    value={form.data.memory_limit_mb}
                    onChange={(e) =>
                        form.setData('memory_limit_mb', e.target.value)
                    }
                    error={form.errors.memory_limit_mb}
                />
                <Input
                    label={t('CPU limit (cores)')}
                    type="number"
                    min={0.05}
                    step={0.05}
                    placeholder={t('Default')}
                    value={form.data.cpu_limit}
                    onChange={(e) => form.setData('cpu_limit', e.target.value)}
                    error={form.errors.cpu_limit}
                />
            </Card>
            <div className="flex flex-wrap gap-2">
                <Button type="submit" loading={form.processing}>
                    {t('Continue')}
                </Button>
                <Button
                    variant="ghost"
                    onClick={() =>
                        router.post(AppSetupController.back(app.slug))
                    }
                >
                    {t('Back')}
                </Button>
            </div>
        </form>
    );
}

function DomainStep({
    app,
    server,
    laravel = false,
}: {
    app: AppSetup;
    server: ServerAddress;
    /** A Laravel app goes on to a last look instead of deploying from here. */
    laravel?: boolean;
}) {
    const { t } = useT();
    const [busy, setBusy] = useState(false);
    const hasDomains = app.domains.length > 0;
    const hidden = app.template?.private === true;
    const [draft, setDraft] = useState<DomainDraft>({
        name: '',
        redirect: 'none',
    });
    const [asking, setAsking] = useState(false);
    const [addError, setAddError] = useState<string | null>(null);

    const finish = () =>
        router.post(
            laravel
                ? AppSetupController.domainContinue(app.slug)
                : AppSetupController.finish(app.slug),
            {},
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    // A domain typed into the form but never added would be lost by deploying now.
    const deploy = () => (draft.name !== '' ? setAsking(true) : finish());

    const addAndDeploy = () => {
        setAddError(null);
        router.post(
            AppDomainController.store(app.slug),
            { name: draft.name, redirect: draft.redirect },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAsking(false);
                    finish();
                },
                onError: (errors) =>
                    setAddError(
                        errors.name ??
                            errors.redirect ??
                            t('The domain could not be added.'),
                    ),
            },
        );
    };

    return (
        <div className="space-y-6">
            {hidden ? (
                <Card title={t('2. Domain')}>
                    <p className="text-sm text-base-content/70">
                        {t(
                            'This app has no web interface, so it takes no domain. Other apps reach it through a link: add one on their Links tab.',
                        )}
                    </p>
                </Card>
            ) : (
                <>
                    <Card
                        title={
                            laravel
                                ? t('Domain')
                                : app.source_type === 'template'
                                  ? t('2. Domain')
                                  : t('4. Domain')
                        }
                        description={t(
                            'Optional: point a domain at this app. You can also do it after the first deployment; the app is reachable only through its domains.',
                        )}
                    >
                        <p className="text-sm text-base-content/70">
                            {t(
                                'Add a domain below, create the DNS records it shows, and CommyPanel connects it and gets a certificate once DNS has updated. You do not have to wait for that to deploy.',
                            )}
                        </p>
                    </Card>
                    <AppDomains
                        slug={app.slug}
                        domains={app.domains}
                        server={server}
                        onDraft={setDraft}
                    />
                </>
            )}
            <Modal
                open={asking}
                onClose={() => setAsking(false)}
                title={t('You typed a domain but did not add it')}
                size="md"
                footer={
                    <>
                        <Button
                            variant="ghost"
                            onClick={() => setAsking(false)}
                        >
                            {t('Go back')}
                        </Button>
                        <Button
                            variant="soft"
                            onClick={() => {
                                setAsking(false);
                                finish();
                            }}
                        >
                            {laravel
                                ? t('Continue without it')
                                : t('Deploy without it')}
                        </Button>
                        <Button onClick={addAndDeploy}>
                            {laravel
                                ? t('Add :domain and continue', {
                                      domain: draft.name,
                                  })
                                : t('Add :domain and deploy', {
                                      domain: draft.name,
                                  })}
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-base-content/80">
                    {t(
                        ':domain is only typed in the form; it has not been added to this app, so deploying now would leave the app without it.',
                        { domain: draft.name },
                    )}
                </p>
                {addError && (
                    <p className="mt-2 text-sm text-error">{addError}</p>
                )}
            </Modal>
            <div className="flex flex-wrap gap-2">
                <Button loading={busy} onClick={deploy}>
                    {laravel
                        ? hasDomains
                            ? t('Continue')
                            : t('Skip')
                        : hasDomains
                          ? t('Create and deploy')
                          : hidden
                            ? t('Deploy')
                            : t('Skip and deploy')}
                </Button>
                {app.source_type === 'template' ? (
                    <Button
                        variant="ghost"
                        onClick={() => {
                            if (
                                confirm(
                                    t('Cancel setup and delete this draft?'),
                                )
                            ) {
                                router.delete(
                                    AppSetupController.cancel(app.slug),
                                );
                            }
                        }}
                    >
                        {t('Cancel setup')}
                    </Button>
                ) : (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            router.post(AppSetupController.back(app.slug))
                        }
                    >
                        {t('Back')}
                    </Button>
                )}
            </div>
        </div>
    );
}
