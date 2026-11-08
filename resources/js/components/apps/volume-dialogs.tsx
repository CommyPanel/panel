import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppVolumeController from '@/actions/App/Http/Controllers/AppVolumeController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { useT } from '@/hooks/use-t';
import { Trans } from '@/lib/trans';
import type { AppVolume } from '@/types';

/** Where the path is explained for people who do not work with Docker every day. */
function PathHelp() {
    return (
        <div className="space-y-3 text-sm text-base-content/80">
            <p>
                <Trans
                    text="Type the folder <b>inside the app</b> whose files must survive a new deployment. It has to be the same path your app writes to, for example <code>/app/uploads</code>."
                    components={{ b: <strong />, code: <code /> }}
                />
            </p>
            <ul className="list-disc space-y-1.5 pl-5">
                <li>
                    <Trans
                        text="<b>Where is “/app”?</b> In the images CommyPanel builds for .NET and Node apps, the app lives in <code>/app</code>, so that is its root folder: <code>/app/uploads</code> is an <code>uploads</code> folder next to your app's own files. If you bring your own Dockerfile, use the folder its <code>WORKDIR</code> names."
                        components={{ b: <strong />, code: <code /> }}
                    />
                </li>
                <li>
                    <Trans
                        text="<b>Go apps</b> are built into a single file called <code>/app</code>, so use another folder, such as <code>/data</code>."
                        components={{ b: <strong />, code: <code /> }}
                    />
                </li>
                <li>
                    <Trans
                        text="<b>Relative paths</b> in your code (such as <code>uploads/</code>) start from that same root folder. An absolute path in your code is the safest match."
                        components={{ b: <strong />, code: <code /> }}
                    />
                </li>
                <li>
                    <Trans
                        text="Paths are <b>case-sensitive</b>: <code>/Uploads/Test</code> and <code>/uploads/test</code> are different folders. Use letters, digits, dots, dashes and slashes."
                        components={{ b: <strong />, code: <code /> }}
                    />
                </li>
            </ul>
        </div>
    );
}

export function AddVolumeModal({
    slug,
    open,
    onClose,
}: {
    slug: string;
    open: boolean;
    onClose: () => void;
}) {
    const { t } = useT();
    const form = useForm({ path: '' });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AppVolumeController.store(slug), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    return (
        <Modal open={open} onClose={close} title={t('Add a volume')} size="md">
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label={t('Path in the container')}
                    placeholder="/app/uploads"
                    required
                    autoFocus
                    autoComplete="off"
                    spellCheck={false}
                    value={form.data.path}
                    onChange={(e) => form.setData('path', e.target.value)}
                    error={form.errors.path}
                />
                <PathHelp />
                <div className="space-y-2 rounded-field border border-base-300 p-3 text-sm text-base-content/80">
                    <p>
                        <Trans
                            text="<b>Where the files are kept.</b> On the server, in a folder CommyPanel names for you from this path (<code>/app/uploads</code> becomes <code>app-uploads</code>). That name is only a label: it does not have to match anything in your app."
                            components={{ b: <strong />, code: <code /> }}
                        />
                    </p>
                    <p>
                        <Trans
                            text="<b>When it starts.</b> The volume is mounted once you <em>apply the changes</em> (a button appears on the app). It starts empty, and it hides whatever the image has at that path, so do not mount over folders your app ships files in."
                            components={{ b: <strong />, em: <em /> }}
                        />
                    </p>
                </div>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        {t('Add volume')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export function RemoveVolumeModal({
    slug,
    volume,
    onClose,
}: {
    slug: string;
    volume: AppVolume | null;
    onClose: () => void;
}) {
    const { t } = useT();
    const form = useForm({ confirm: '' });

    const close = () => {
        form.reset();
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!volume || form.data.confirm !== volume.path) return;

        router.delete(
            AppVolumeController.destroy({ app: slug, volume: volume.id }),
            {
                preserveScroll: true,
                onSuccess: close,
            },
        );
    };

    return (
        <Modal
            open={volume !== null}
            onClose={close}
            title={t('Remove this volume?')}
            size="md"
        >
            {volume && (
                <form onSubmit={submit} className="space-y-4">
                    <p className="text-sm">
                        <Trans
                            text="<code>:path</code> will stop being a persistent volume."
                            components={{ code: <code /> }}
                            values={{ path: volume.path }}
                        />
                    </p>
                    <ul className="list-disc space-y-1.5 pl-5 text-sm">
                        <li>
                            <Trans
                                text="The running version keeps using it until you <b>apply the changes</b>. From then on the path is just an ordinary folder inside the container, and anything the app writes there is <b2>lost at every deployment</b2>."
                                components={{ b: <strong />, b2: <strong /> }}
                            />
                        </li>
                        <li>
                            <Trans
                                text="The files are <b>not deleted</b>. They stay on the server, and the volume stays in the list as “not attached”. Once a deployment without it is running you can delete the files with “Delete data”."
                                components={{ b: <strong /> }}
                            />
                        </li>
                    </ul>
                    <Input
                        label={t('Type :path to confirm', {
                            path: volume.path,
                        })}
                        autoComplete="off"
                        autoFocus
                        spellCheck={false}
                        value={form.data.confirm}
                        onChange={(e) =>
                            form.setData('confirm', e.target.value)
                        }
                    />
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button variant="ghost" onClick={close}>
                            {t('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            variant="error"
                            disabled={form.data.confirm !== volume.path}
                        >
                            {t('Remove volume')}
                        </Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export function AboutVolumesModal({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const { t } = useT();

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={t('How volumes work')}
            size="lg"
        >
            <div className="space-y-4 text-sm text-base-content/80">
                <p>
                    <Trans
                        text="Everything an app writes inside its container is thrown away when the next deployment replaces the container. A <b>persistent volume</b> is a folder that is kept on the server and handed to every container of the app, so its files (uploads, a SQLite database) survive."
                        components={{ b: <strong /> }}
                    />
                </p>
                <ul className="list-disc space-y-2 pl-5">
                    <li>
                        <Trans
                            text="<b>Two names.</b> The <em>path</em> is where the app sees the folder and is the one that must match your app. The <em>folder</em> is where the files live on the server, made up by CommyPanel, and does not have to match anything."
                            components={{
                                b: <strong />,
                                em: <em />,
                                code: <code />,
                            }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>New deployments.</b> Adding or removing a volume takes effect when you apply the changes or deploy, for the app and for its release command."
                            components={{
                                b: <strong />,
                                em: <em />,
                                code: <code />,
                            }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>While switching.</b> The old and the new container share a volume during a deployment, so make sure two versions can use the same files for a moment."
                            components={{
                                b: <strong />,
                                em: <em />,
                                code: <code />,
                            }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>Rollbacks.</b> A rollback does not roll back volume data: the files stay as the running version left them."
                            components={{
                                b: <strong />,
                                em: <em />,
                                code: <code />,
                            }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>Hidden files.</b> Whatever the image has at that path is hidden by the volume, which starts empty."
                            components={{
                                b: <strong />,
                                em: <em />,
                                code: <code />,
                            }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>Removing.</b> Removing a volume stops mounting it but keeps its files on the server. Deleting the app deletes them."
                            components={{
                                b: <strong />,
                                em: <em />,
                                code: <code />,
                            }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>Locking.</b> A locked volume cannot be removed, and the app cannot be deleted while it is locked."
                            components={{
                                b: <strong />,
                                em: <em />,
                                code: <code />,
                            }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>Limits.</b> Up to 8 volumes per app. <code>/proc</code>, <code>/sys</code> and <code>/dev</code> cannot be used."
                            components={{
                                b: <strong />,
                                em: <em />,
                                code: <code />,
                            }}
                        />
                    </li>
                    <li>
                        <Trans
                            text="<b>Who can write.</b> The folder is writable for whichever user the app runs as, and cannot be reached from anywhere else on the server."
                            components={{
                                b: <strong />,
                                em: <em />,
                                code: <code />,
                            }}
                        />
                    </li>
                </ul>
                <div className="flex justify-end">
                    <Button onClick={onClose}>{t('Got it')}</Button>
                </div>
            </div>
        </Modal>
    );
}
