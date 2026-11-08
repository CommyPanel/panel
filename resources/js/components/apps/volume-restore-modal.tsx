import { TypedConfirmModal } from '@/components/ui/typed-confirm-modal';
import { useT } from '@/hooks/use-t';
import { Trans } from '@/lib/trans';

/**
 * Put an archive back over its volume. The app stops for a moment and the files the
 * volume holds now are replaced, after a safety archive.
 */
export function VolumeRestoreModal({
    file,
    volume,
    busy,
    error,
    onSubmit,
    onClose,
}: {
    file: string | null;
    volume: string;
    busy: boolean;
    error?: string;
    onSubmit: () => void;
    onClose: () => void;
}) {
    const { t } = useT();

    return (
        <TypedConfirmModal
            open={file !== null}
            title={t('Restore this volume backup?')}
            expected={volume}
            confirmLabel={t('Restore the volume')}
            loading={busy}
            error={error}
            onConfirm={onSubmit}
            onClose={onClose}
        >
            <p>
                <Trans
                    text="<code>:file</code> is unpacked over the volume <b>:volume</b>. The files the volume holds now are <b>replaced</b> by the ones in the archive."
                    components={{
                        code: <code className="break-all" />,
                        b: <strong />,
                    }}
                    values={{ file: file ?? '', volume }}
                />
            </p>
            <ul className="list-disc space-y-1.5 pl-5 text-base-content/80">
                <li>
                    {t(
                        'A safety archive of the volume as it is now is made first and stays in the list.',
                    )}
                </li>
                <li>
                    {t(
                        'The app is stopped for a moment while the files are put back, and started again afterwards, also when the restore fails.',
                    )}
                </li>
            </ul>
        </TypedConfirmModal>
    );
}
