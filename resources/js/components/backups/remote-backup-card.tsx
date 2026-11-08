import { router, useForm, usePage } from '@inertiajs/react';
import { CloudUpload, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import BackupRemoteController from '@/actions/App/Http/Controllers/BackupRemoteController';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useT } from '@/hooks/use-t';
import { useConfirmedAction } from '@/hooks/use-confirmed-action';
import { timeAgo } from '@/lib/format';
import type { RemoteBackupSettings } from '@/types';

/**
 * Copies every new backup to an S3-compatible storage (Hetzner, Cloudflare R2,
 * Backblaze, Wasabi, MinIO, Amazon), so the backups survive the loss of this
 * server's disk. The secret key is write-only.
 */
export function RemoteBackupCard({
    remote,
    manage,
}: {
    remote: RemoteBackupSettings;
    manage: boolean;
}) {
    const { t } = useT();
    const { errors } = usePage().props;
    const confirmed = useConfirmedAction();
    const form = useForm({
        enabled: remote.enabled,
        endpoint: remote.endpoint,
        region: remote.region,
        bucket: remote.bucket,
        prefix: remote.prefix,
        access_key: remote.access_key,
        secret_key: '',
        keep: remote.keep,
    });
    const configured = remote.endpoint !== '';

    const save = (event: FormEvent) => {
        event.preventDefault();
        void confirmed.run(() =>
            form.put(BackupRemoteController.update().url, {
                preserveScroll: true,
                onSuccess: () => form.setData('secret_key', ''),
            }),
        );
    };

    const test = () =>
        form.post(BackupRemoteController.test().url, { preserveScroll: true });

    const remove = () =>
        void confirmed.run(() => {
            if (
                !window.confirm(
                    t(
                        'Remove these settings? Copies already in the storage stay there.',
                    ),
                )
            ) {
                return;
            }

            router.delete(BackupRemoteController.destroy(), {
                preserveScroll: true,
            });
        });

    return (
        <Card
            title={t('Copy to another place (S3)')}
            description={t(
                "A backup on this server's disk is lost with the disk. Each new backup can be copied to any S3-compatible storage: Hetzner Object Storage, Cloudflare R2, Backblaze B2, Wasabi, MinIO or Amazon S3.",
            )}
        >
            {errors.remote && <Alert variant="error">{errors.remote}</Alert>}
            {configured && remote.last_error && (
                <Alert variant="error">
                    {t('The last copy failed: :error', {
                        error: remote.last_error,
                    })}
                </Alert>
            )}
            {configured && remote.enabled && remote.last_ok_at && (
                <p className="text-sm text-base-content/70">
                    {t('Last copy made :when.', {
                        when: timeAgo(remote.last_ok_at),
                    })}
                </p>
            )}

            {manage ? (
                <form onSubmit={save} className="space-y-4">
                    <Input
                        label={t('Address of the storage')}
                        placeholder="https://fsn1.your-objectstorage.com"
                        required
                        autoComplete="off"
                        spellCheck={false}
                        value={form.data.endpoint}
                        onChange={(e) =>
                            form.setData('endpoint', e.target.value)
                        }
                        error={form.errors.endpoint}
                        hint={t(
                            'The https address your provider gives you, without a folder. For Hetzner, https://fsn1.your-objectstorage.com (or nbg1, hel1).',
                        )}
                    />
                    <Input
                        label={t('Region')}
                        placeholder="fsn1"
                        autoComplete="off"
                        spellCheck={false}
                        value={form.data.region}
                        onChange={(e) => form.setData('region', e.target.value)}
                        error={form.errors.region}
                        hint={t(
                            'Hetzner: fsn1, nbg1 or hel1. Cloudflare R2: auto. Amazon: such as eu-central-1. Leave empty if unsure (us-east-1).',
                        )}
                    />
                    <Input
                        label={t('Bucket')}
                        required
                        autoComplete="off"
                        spellCheck={false}
                        value={form.data.bucket}
                        onChange={(e) => form.setData('bucket', e.target.value)}
                        error={form.errors.bucket}
                        hint={t('Create it at your provider first.')}
                    />
                    <Input
                        label={t('Folder in the bucket (optional)')}
                        placeholder="commypanel/main-server"
                        autoComplete="off"
                        spellCheck={false}
                        value={form.data.prefix}
                        onChange={(e) => form.setData('prefix', e.target.value)}
                        error={form.errors.prefix}
                        hint={t('Lets several servers share one bucket.')}
                    />
                    <Input
                        label={t('Access key')}
                        required
                        autoComplete="off"
                        spellCheck={false}
                        value={form.data.access_key}
                        onChange={(e) =>
                            form.setData('access_key', e.target.value)
                        }
                        error={form.errors.access_key}
                    />
                    <Input
                        label={t('Secret key')}
                        type="password"
                        autoComplete="new-password"
                        required={!remote.has_secret}
                        placeholder={
                            remote.has_secret
                                ? t('Saved. Type to replace it.')
                                : ''
                        }
                        value={form.data.secret_key}
                        onChange={(e) =>
                            form.setData('secret_key', e.target.value)
                        }
                        error={form.errors.secret_key}
                        hint={t(
                            'Stored encrypted and never shown again. Use a key that may only write to this bucket.',
                        )}
                    />
                    <Input
                        label={t('Copies to keep of each backup')}
                        type="number"
                        min={1}
                        max={365}
                        required
                        value={form.data.keep}
                        onChange={(e) =>
                            form.setData('keep', Number(e.target.value))
                        }
                        error={form.errors.keep}
                        hint={t(
                            'Older copies of the same backup are deleted from the storage. Files that are not CommyPanel backups are never touched.',
                        )}
                    />
                    <label className="flex cursor-pointer items-center gap-3">
                        <input
                            type="checkbox"
                            className="checkbox"
                            checked={form.data.enabled}
                            onChange={(e) =>
                                form.setData('enabled', e.target.checked)
                            }
                        />
                        <span className="text-sm">
                            {t('Copy new backups to this storage')}
                        </span>
                    </label>
                    <p className="text-sm text-base-content/70">
                        {t(
                            'Panel backups, database dumps and volume archives are copied right after they are made, starting with the next one. The copy is made from this server, in the background; it is the one place besides update checks where the panel contacts the outside by itself, and only once you switch it on here.',
                        )}
                    </p>
                    <div className="flex flex-wrap justify-end gap-2">
                        {configured && (
                            <Button variant="ghost" onClick={remove}>
                                <Trash2 className="size-4" aria-hidden />
                                {t('Remove')}
                            </Button>
                        )}
                        <Button
                            variant="soft"
                            onClick={test}
                            loading={form.processing}
                        >
                            <CloudUpload className="size-4" aria-hidden />
                            {t('Test the connection')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('Save')}
                        </Button>
                    </div>
                </form>
            ) : (
                <p className="text-sm text-base-content/70">
                    {configured && remote.enabled
                        ? t('Backups are copied to :bucket at :endpoint.', {
                              bucket: remote.bucket,
                              endpoint: remote.endpoint,
                          })
                        : t('Backups are not copied off this server.')}
                </p>
            )}
            {confirmed.modal}
        </Card>
    );
}
