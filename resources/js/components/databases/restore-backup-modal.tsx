import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import type { DatabaseBackupFile } from '@/types/database';

/** A name for the copy, such as shop_restored, that fits the 31 characters allowed. */
function suggest(database: string): string {
    return `${database}_restored`.slice(0, 31);
}

export function RestoreBackupModal({
    file,
    error,
    busy,
    onSubmit,
    onClose,
}: {
    file: DatabaseBackupFile | null;
    error?: string;
    busy: boolean;
    onSubmit: (name: string) => void;
    onClose: () => void;
}) {
    const [name, setName] = useState('');
    // The last file stays on screen while the dialog fades out.
    const [shown, setShown] = useState<DatabaseBackupFile | null>(null);

    useEffect(() => {
        if (!file) return;

        setShown(file);
        setName(suggest(file.database));
    }, [file]);

    return (
        <Modal
            open={file !== null}
            onClose={onClose}
            title="Restore this backup"
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    onSubmit(name);
                }}
                className="space-y-4"
            >
                <p className="text-sm">
                    <code className="break-all">{shown?.name}</code> is loaded
                    into a <strong>new</strong> database. Nothing that exists
                    now is changed or overwritten.
                </p>
                <Input
                    label="Name of the new database"
                    required
                    autoFocus
                    autoComplete="off"
                    spellCheck={false}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    error={error}
                    hint="Lowercase letters, digits and underscores, starting with a letter."
                />
                <ul className="list-disc space-y-1.5 pl-5 text-sm text-base-content/80">
                    <li>
                        It gets its own user and password, like any database you
                        add. Large backups take a while; the database shows
                        &quot;Restoring&quot; until it is done.
                    </li>
                    <li>
                        To use the copy, link it to the app on its{' '}
                        <strong>Links</strong> tab, deploy again, and delete the
                        old database when you are sure.
                    </li>
                    <li>
                        Only backups CommyPanel made itself can be restored. One
                        made before this feature existed, or changed since, is
                        refused.
                    </li>
                </ul>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={busy}>
                        Restore into a new database
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
