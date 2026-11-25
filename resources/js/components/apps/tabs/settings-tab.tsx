import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import { RuntimeFields, SourceFields } from '@/components/apps/app-fields';
import type { AppFormData } from '@/components/apps/app-fields';
import { AccessTokenCard } from '@/components/apps/access-token-card';
import { AlertsCard } from '@/components/apps/alerts-card';
import { DeployKeyCard } from '@/components/apps/deploy-key-card';
import { UploadSourceCard } from '@/components/apps/upload-source-card';
import { WebhookCard } from '@/components/apps/webhook-card';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useT } from '@/hooks/use-t';
import type { AppSettings, AppWebhook } from '@/types';

export function SettingsTab({
    app,
    webhook,
}: {
    app: AppSettings;
    webhook: AppWebhook | null;
}) {
    const { t } = useT();
    // A quick-install app has no repository and no archive: only a name and the runtime.
    const git = app.source_type === 'git';
    const form = useForm<AppFormData>({
        name: app.name,
        git_url: app.git_url ?? '',
        git_branch: app.git_branch,
        container_port: String(app.container_port),
        health_path: app.health_path,
        health_timeout: app.health_timeout?.toString() ?? '',
        deploy_strategy: app.deploy_strategy,
        release_command: app.release_command ?? '',
        release_script: app.release_script ?? '',
        memory_limit_mb: app.memory_limit_mb?.toString() ?? '',
        cpu_limit: app.cpu_limit?.toString() ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppController.update(app.slug), { preserveScroll: true });
    };

    const fields = {
        data: form.data,
        errors: form.errors,
        setData: form.setData,
    };

    return (
        <div className="grid items-start gap-6 xl:grid-cols-3">
            <div className="min-w-0 space-y-6 xl:col-span-2">
                <form onSubmit={submit} className="space-y-6">
                    {!git ? (
                        <Card title={t('Name')}>
                            <Input
                                label={t('App name')}
                                required
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                error={form.errors.name}
                            />
                        </Card>
                    ) : (
                        <Card
                            title={t('Source')}
                            description={t('Where the code comes from.')}
                        >
                            <SourceFields {...fields} />
                        </Card>
                    )}
                    <Card
                        title={t('Runtime')}
                        description={t(
                            'How the app runs. Changes apply from the next deployment.',
                        )}
                    >
                        <RuntimeFields
                            {...fields}
                            releaseScript={app.uses_processes}
                        />
                    </Card>
                    <Button type="submit" loading={form.processing}>
                        {t('Save settings')}
                    </Button>
                </form>
            </div>
            <div className="min-w-0 space-y-6">
                <AlertsCard app={app} />
                {app.source_type === 'upload' ? (
                    <UploadSourceCard slug={app.slug} />
                ) : git ? (
                    <>
                        <DeployKeyCard
                            slug={app.slug}
                            publicKey={app.deploy_key_public}
                        />
                        <AccessTokenCard app={app} />
                        {webhook && (
                            <WebhookCard slug={app.slug} webhook={webhook} />
                        )}
                    </>
                ) : null}
            </div>
        </div>
    );
}
