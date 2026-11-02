import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import DatabaseServerController from '@/actions/App/Http/Controllers/DatabaseServerController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { Select } from '@/components/ui/select';
import type { EngineChoice } from '@/types';

export function AddServerModal({
    open,
    onClose,
    engines,
}: {
    open: boolean;
    onClose: () => void;
    engines: EngineChoice[];
}) {
    const first = engines[0];
    const form = useForm({
        name: '',
        engine: first?.value ?? 'mariadb',
        version: first?.versions[0] ?? '',
    });

    const versions =
        engines.find((e) => e.value === form.data.engine)?.versions ?? [];

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(DatabaseServerController.store(), { onSuccess: close });
    };

    return (
        <Modal open={open} onClose={close} title="Add a database server">
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Name"
                    placeholder="Main database"
                    required
                    autoFocus
                    autoComplete="off"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={form.errors.name}
                    hint="Only you see this name. Apps reach the server by a short name made from it."
                />
                <Select
                    label="Database"
                    value={form.data.engine}
                    onChange={(e) => {
                        const engine = e.target.value;
                        form.setData({
                            ...form.data,
                            engine,
                            version:
                                engines.find((x) => x.value === engine)
                                    ?.versions[0] ?? '',
                        });
                    }}
                    error={form.errors.engine}
                >
                    {engines.map((e) => (
                        <option key={e.value} value={e.value}>
                            {e.label}
                        </option>
                    ))}
                </Select>
                <Select
                    label="Version"
                    value={form.data.version}
                    onChange={(e) => form.setData('version', e.target.value)}
                    error={form.errors.version}
                >
                    {versions.map((v) => (
                        <option key={v} value={v}>
                            {v}
                        </option>
                    ))}
                </Select>
                <ul className="list-disc space-y-1.5 pl-5 text-sm text-base-content/80">
                    <li>
                        The server runs from the official image, downloaded from
                        Docker Hub. The first start can take a few minutes.
                    </li>
                    <li>
                        It is <strong>never reachable from the internet</strong>
                        : no port is published. An app can reach it only after
                        you link a database to that app.
                    </li>
                    <li>
                        Its data is kept on this server and survives restarts.
                        Every database is also dumped to a file each night, and
                        the last 7 are kept on this server.
                    </li>
                </ul>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Add server
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
