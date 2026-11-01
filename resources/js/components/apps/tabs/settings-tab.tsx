import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppController from '@/actions/App/Http/Controllers/AppController';
import { RuntimeFields, SourceFields } from '@/components/apps/app-fields';
import type { AppFormData } from '@/components/apps/app-fields';
import { AccessTokenCard } from '@/components/apps/access-token-card';
import { DeployKeyCard } from '@/components/apps/deploy-key-card';
import { VolumesCard } from '@/components/apps/volumes-card';
import { UploadSourceCard } from '@/components/apps/upload-source-card';
import { WebhookCard } from '@/components/apps/webhook-card';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { AppSettings, AppWebhook } from '@/types';

export function SettingsTab({
    app,
    webhook,
}: {
    app: AppSettings;
    webhook: AppWebhook | null;
}) {
    const upload = app.source_type === 'upload';
    const form = useForm<AppFormData>({
        name: app.name,
        git_url: app.git_url ?? '',
        git_branch: app.git_branch,
        container_port: String(app.container_port),
        health_path: app.health_path,
        release_command: app.release_command ?? '',
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
                    {upload ? (
                        <Card title="Name">
                            <Input
                                label="App name"
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
                            title="Source"
                            description="Where the code comes from."
                        >
                            <SourceFields {...fields} />
                        </Card>
                    )}
                    <Card
                        title="Runtime"
                        description="How the app runs. Changes apply from the next deployment."
                    >
                        <RuntimeFields {...fields} />
                    </Card>
                    <Button type="submit" loading={form.processing}>
                        Save settings
                    </Button>
                </form>
                <VolumesCard slug={app.slug} volumes={app.volumes} />
            </div>
            <div className="min-w-0 space-y-6">
                {upload ? (
                    <UploadSourceCard slug={app.slug} />
                ) : (
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
                )}
            </div>
        </div>
    );
}
