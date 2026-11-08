import { router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Upload, XCircle } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import AppSetupController from '@/actions/App/Http/Controllers/AppSetupController';
import DeployKeyController from '@/actions/App/Http/Controllers/DeployKeyController';
import { AccessTokenCard } from '@/components/apps/access-token-card';
import { AppDomains } from '@/components/apps/app-domains';
import { ReleaseCommandInput } from '@/components/apps/app-fields';
import { SetupSteps } from '@/components/apps/setup-steps';
import { PageHeader } from '@/components/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useT } from '@/hooks/use-t';
import AppLayout from '@/layouts/app-layout';
import { postJson } from '@/lib/post-json';
import type { AppSetup, ServerAddress, SourceAccess } from '@/types';

type Provider = { name: string; url: string | null; steps: string[] };
type Props = {
    app: AppSetup;
    provider: Provider | null;
    server: ServerAddress;
    limits: { max_archive_mb: number };
};

export default function Setup({ app, provider, server, limits }: Props) {
    const { t } = useT();

    return (
        <AppLayout title={t('Set up :name', { name: app.name })}>
            <PageHeader
                title={t('Set up :name', { name: app.name })}
                description={t(
                    'A few steps, then CommyPanel builds and starts your app.',
                )}
            />
            <SetupSteps current={app.setup_step} source={app.source_type} />
            {app.setup_step === 'source' ? (
                app.source_type === 'git' ? (
                    <GitStep app={app} provider={provider} />
                ) : (
                    <UploadStep app={app} maxMb={limits.max_archive_mb} />
                )
            ) : app.setup_step === 'settings' ? (
                <SettingsStep app={app} />
            ) : (
                <DomainStep app={app} server={server} />
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

function SettingsStep({ app }: { app: AppSetup }) {
    const { t } = useT();
    const form = useForm({
        container_port: String(app.container_port),
        health_path: app.health_path,
        release_command: app.release_command ?? '',
        memory_limit_mb: app.memory_limit_mb ? String(app.memory_limit_mb) : '',
        cpu_limit: app.cpu_limit ? String(app.cpu_limit) : '',
        env: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(AppSetupController.settings(app.slug).url);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card
                title={t('3. Settings')}
                description={t(
                    'You can change all of this later. Variables are encrypted and shown only by name.',
                )}
            >
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
                        onChange={(e) => form.setData('env', e.target.value)}
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
                <ReleaseCommandInput
                    value={form.data.release_command}
                    onChange={(value) => form.setData('release_command', value)}
                    error={form.errors.release_command}
                />
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

function DomainStep({ app, server }: { app: AppSetup; server: ServerAddress }) {
    const { t } = useT();
    const [busy, setBusy] = useState(false);
    const hasDomains = app.domains.length > 0;

    return (
        <div className="space-y-6">
            <Card
                title={t('4. Domain')}
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
            <AppDomains slug={app.slug} domains={app.domains} server={server} />
            <div className="flex flex-wrap gap-2">
                <Button
                    loading={busy}
                    onClick={() =>
                        router.post(
                            AppSetupController.finish(app.slug),
                            {},
                            {
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                            },
                        )
                    }
                >
                    {hasDomains ? t('Create and deploy') : t('Skip and deploy')}
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
        </div>
    );
}
