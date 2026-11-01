import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import AppVolumeController from '@/actions/App/Http/Controllers/AppVolumeController';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { AppVolume } from '@/types';

type Props = { slug: string; volumes: AppVolume[] };

/**
 * Folders of the app's container that are kept between deployments. Without
 * one, everything an app writes is gone with its container at the next deploy.
 */
export function VolumesCard({ slug, volumes }: Props) {
    const form = useForm({ path: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppVolumeController.store(slug), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const remove = (volume: AppVolume) =>
        router.delete(
            AppVolumeController.destroy({ app: slug, volume: volume.id }),
            { preserveScroll: true },
        );

    return (
        <Card
            title="Persistent volumes"
            description="Folders in the container that keep their files from one deployment to the next, for uploads or a SQLite database. Changes apply from the next deployment."
        >
            {volumes.length > 0 ? (
                <ul className="divide-y divide-base-300 rounded-field border border-base-300">
                    {volumes.map((volume) => (
                        <li
                            key={volume.id}
                            className="flex items-center justify-between gap-2 py-1 pr-1 pl-3"
                        >
                            <span className="min-w-0">
                                <code className="block truncate text-sm">
                                    {volume.path}
                                </code>
                                <span className="block truncate text-xs text-base-content/70">
                                    Stored as volumes/{slug}/{volume.name}
                                </span>
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                aria-label={`Stop keeping ${volume.path}`}
                                onClick={() => remove(volume)}
                            >
                                <Trash2 className="size-4" aria-hidden />
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-sm text-base-content/70">
                    Nothing is kept yet: files the app writes disappear when a
                    new version replaces the container.
                </p>
            )}

            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Path in the container"
                    placeholder="/app/data"
                    required
                    autoComplete="off"
                    spellCheck={false}
                    value={form.data.path}
                    onChange={(e) => form.setData('path', e.target.value)}
                    error={form.errors.path}
                    hint="An absolute path, such as /app/uploads."
                />
                <Button type="submit" variant="soft" loading={form.processing}>
                    Add volume
                </Button>
            </form>

            <ul className="list-disc space-y-1 pl-5 text-sm text-base-content/70">
                <li>
                    The old and the new container share a volume while a
                    deployment switches over, so both can read and write it.
                </li>
                <li>
                    A rollback does not roll back volume data: the files stay as
                    the running version left them.
                </li>
                <li>
                    Whatever the image has at that path is hidden by the volume,
                    which starts empty.
                </li>
                <li>
                    Stopping to keep a path leaves its files on the server;
                    deleting the app deletes them.
                </li>
            </ul>
        </Card>
    );
}
