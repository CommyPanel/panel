import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Modal } from '@/components/ui/modal';
import { useT } from '@/hooks/use-t';
import { Trans } from '@/lib/trans';
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
    const { t } = useT();
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
            title={t('Restore this backup')}
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    onSubmit(name);
                }}
                className="space-y-4"
            >
                <p className="text-sm">
                    <Trans
                        text="<code>:name</code> is loaded into a <b>new</b> database. Nothing that exists now is changed or overwritten."
                        components={{
                            code: <code className="break-all" />,
                            b: <strong />,
                        }}
                        values={{ name: shown?.name ?? '' }}
                    />
                </p>
                <Input
                    label={t('Name of the new database')}
                    required
                    autoFocus
                    autoComplete="off"
                    spellCheck={false}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    error={error}
                    hint={t(
                        'Lowercase letters, digits and underscores, starting with a letter.',
                    )}
                />
                <ul className="list-disc space-y-1.5 pl-5 text-sm text-base-content/80">
                    <li>
                        {t(
                            'It gets its own user and password, like any database you add. Large backups take a while; the database shows "Restoring" until it is done.',
                        )}
                    </li>
                    <li>
                        <Trans
                            text="To use the copy, link it to the app on its <b>Links</b> tab, deploy again, and delete the old database when you are sure."
                            components={{ b: <strong /> }}
                        />
                    </li>
                </ul>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="ghost" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={busy}>
                        {t('Restore into a new database')}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
