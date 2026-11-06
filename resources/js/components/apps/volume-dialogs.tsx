import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppVolumeController from '@/actions/App/Http/Controllers/AppVolumeController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import type { AppVolume } from '@/types';

/** Where the path is explained for people who do not work with Docker every day. */
function PathHelp() {
    return (
        <div className="space-y-3 text-sm text-base-content/80">
            <p>
                Type the folder <strong>inside the app</strong> whose files must
                survive a new deployment. It has to be the same path your app
                writes to, for example <code>/app/uploads</code>.
            </p>
            <ul className="list-disc space-y-1.5 pl-5">
                <li>
                    <strong>Where is &ldquo;/app&rdquo;?</strong> In the images
                    CommyPanel builds for .NET and Node apps, the app lives in{' '}
                    <code>/app</code>, so that is its root folder:{' '}
                    <code>/app/uploads</code> is an <code>uploads</code> folder
                    next to your app&apos;s own files. If you bring your own
                    Dockerfile, use the folder its <code>WORKDIR</code> names.
                </li>
                <li>
                    <strong>Go apps</strong> are built into a single file called{' '}
                    <code>/app</code>, so use another folder, such as{' '}
                    <code>/data</code>.
                </li>
                <li>
                    <strong>Relative paths</strong> in your code (such as{' '}
                    <code>uploads/</code>) start from that same root folder. An
                    absolute path in your code is the safest match.
                </li>
                <li>
                    Paths are <strong>case-sensitive</strong>:{' '}
                    <code>/Uploads/Test</code> and <code>/uploads/test</code>{' '}
                    are different folders. Use letters, digits, dots, dashes and
                    slashes.
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
        <Modal open={open} onClose={close} title="Add a volume" size="md">
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Path in the container"
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
                        <strong>Where the files are kept.</strong> On the
                        server, in a folder CommyPanel names for you from this
                        path (<code>/app/uploads</code> becomes{' '}
                        <code>app-uploads</code>). That name is only a label: it
                        does not have to match anything in your app.
                    </p>
                    <p>
                        <strong>When it starts.</strong> The volume is mounted
                        once you <em>apply the changes</em> (a button appears on
                        the app). It starts empty, and it hides whatever the
                        image has at that path, so do not mount over folders
                        your app ships files in.
                    </p>
                </div>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Add volume
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
            title="Remove this volume?"
            size="md"
        >
            {volume && (
                <form onSubmit={submit} className="space-y-4">
                    <p className="text-sm">
                        <code>{volume.path}</code> will stop being a persistent
                        volume.
                    </p>
                    <ul className="list-disc space-y-1.5 pl-5 text-sm">
                        <li>
                            The running version keeps using it until you{' '}
                            <strong>apply the changes</strong>. From then on the
                            path is just an ordinary folder inside the
                            container, and anything the app writes there is{' '}
                            <strong>lost at every deployment</strong>.
                        </li>
                        <li>
                            The files are <strong>not deleted</strong>. They
                            stay on the server, and the volume stays in the list
                            as &ldquo;not attached&rdquo;. Once a deployment
                            without it is running you can delete the files with
                            &ldquo;Delete data&rdquo;.
                        </li>
                    </ul>
                    <Input
                        label={`Type ${volume.path} to confirm`}
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
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="error"
                            disabled={form.data.confirm !== volume.path}
                        >
                            Remove volume
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
    return (
        <Modal open={open} onClose={onClose} title="How volumes work" size="lg">
            <div className="space-y-4 text-sm text-base-content/80">
                <p>
                    Everything an app writes inside its container is thrown away
                    when the next deployment replaces the container. A{' '}
                    <strong>persistent volume</strong> is a folder that is kept
                    on the server and handed to every container of the app, so
                    its files (uploads, a SQLite database) survive.
                </p>
                <ul className="list-disc space-y-2 pl-5">
                    <li>
                        <strong>Two names.</strong> The <em>path</em> is where
                        the app sees the folder and is the one that must match
                        your app. The <em>folder</em> is where the files live on
                        the server, made up by CommyPanel, and does not have to
                        match anything.
                    </li>
                    <li>
                        <strong>New deployments.</strong> Adding or removing a
                        volume takes effect when you apply the changes or
                        deploy, for the app and for its release command.
                    </li>
                    <li>
                        <strong>While switching.</strong> The old and the new
                        container share a volume during a deployment, so make
                        sure two versions can use the same files for a moment.
                    </li>
                    <li>
                        <strong>Rollbacks.</strong> A rollback does not roll
                        back volume data: the files stay as the running version
                        left them.
                    </li>
                    <li>
                        <strong>Hidden files.</strong> Whatever the image has at
                        that path is hidden by the volume, which starts empty.
                    </li>
                    <li>
                        <strong>Removing.</strong> Removing a volume stops
                        mounting it but keeps its files on the server. Deleting
                        the app deletes them.
                    </li>
                    <li>
                        <strong>Locking.</strong> A locked volume cannot be
                        removed, and the app cannot be deleted while it is
                        locked.
                    </li>
                    <li>
                        <strong>Limits.</strong> Up to 8 volumes per app.{' '}
                        <code>/proc</code>, <code>/sys</code> and{' '}
                        <code>/dev</code> cannot be used.
                    </li>
                    <li>
                        <strong>Who can write.</strong> The folder is writable
                        for whichever user the app runs as, and cannot be
                        reached from anywhere else on the server.
                    </li>
                </ul>
                <div className="flex justify-end">
                    <Button onClick={onClose}>Got it</Button>
                </div>
            </div>
        </Modal>
    );
}
