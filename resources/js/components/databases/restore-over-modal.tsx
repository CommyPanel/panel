import { useEffect, useState } from 'react';
import { Select } from '@/components/ui/select';
import { TypedConfirmModal } from '@/components/ui/typed-confirm-modal';
import { useT } from '@/hooks/use-t';
import { Trans } from '@/lib/trans';
import type { DatabaseBackupFile, ManagedDatabase } from '@/types';

/**
 * Load a dump over a database that exists: everything in it is replaced. The person picks
 * the database, ticks that they understand and types its name.
 */
export function RestoreOverModal({
    file,
    databases,
    error,
    busy,
    onSubmit,
    onClose,
}: {
    file: DatabaseBackupFile | null;
    databases: ManagedDatabase[];
    error?: string;
    busy: boolean;
    onSubmit: (database: string) => void;
    onClose: () => void;
}) {
    const { t } = useT();
    const [target, setTarget] = useState('');
    const [understood, setUnderstood] = useState(false);
    // The last file stays on screen while the dialog fades out.
    const [shown, setShown] = useState<DatabaseBackupFile | null>(null);

    useEffect(() => {
        if (!file) return;

        setShown(file);
        setUnderstood(false);
        setTarget(
            databases.some((d) => d.name === file.database)
                ? file.database
                : (databases[0]?.name ?? ''),
        );
    }, [file, databases]);

    const database = databases.find((d) => d.name === target);

    return (
        <TypedConfirmModal
            open={file !== null}
            title={t('Restore over a database')}
            expected={target || '-'}
            confirmLabel={t('Replace the database')}
            loading={busy}
            disabled={!understood || !database}
            error={error}
            onConfirm={() => onSubmit(target)}
            onClose={onClose}
        >
            <p>
                <Trans
                    text="<code>:name</code> is loaded over a database that exists now. Everything in that database is <b>replaced</b> by the contents of the dump."
                    components={{
                        code: <code className="break-all" />,
                        b: <strong />,
                    }}
                    values={{ name: shown?.name ?? '' }}
                />
            </p>
            <Select
                label={t('Database to replace')}
                value={target}
                onChange={(e) => setTarget(e.target.value)}
            >
                {databases.map((d) => (
                    <option key={d.id} value={d.name}>
                        {d.name}
                    </option>
                ))}
            </Select>
            <ul className="list-disc space-y-1.5 pl-5 text-base-content/80">
                <li>
                    {t(
                        'A safety dump of the database as it is now is made first and stays in the list of backups. If loading fails, it is put back automatically.',
                    )}
                </li>
                <li>
                    {t(
                        'Its user and password stay the same, so linked apps keep working afterwards.',
                    )}
                </li>
            </ul>
            {database && database.apps.length > 0 && (
                <div className="rounded-field border border-warning/40 bg-warning/10 p-3">
                    <p className="font-medium">
                        {t(
                            'These apps use this database and will see errors while it is replaced:',
                        )}
                    </p>
                    <p className="mt-1">
                        {database.apps.map((a) => a.name).join(', ')}
                    </p>
                </div>
            )}
            <label className="flex cursor-pointer items-start gap-3">
                <input
                    type="checkbox"
                    className="checkbox mt-0.5 checkbox-error"
                    checked={understood}
                    onChange={(e) => setUnderstood(e.target.checked)}
                />
                <span>
                    {t(
                        'I understand everything in :database is replaced by this dump',
                        { database: target },
                    )}
                </span>
            </label>
        </TypedConfirmModal>
    );
}
