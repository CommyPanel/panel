import { Lock } from 'lucide-react';
import { useT } from '@/hooks/use-t';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Shows that an app or a volume is locked, next to its name. A locked item
 * cannot be deleted; see the Lock card on an app's Advanced tab.
 */
export function LockIcon({
    what,
    align = 'start',
}: {
    what: 'app' | 'volume';
    align?: 'start' | 'end';
}) {
    const { t } = useT();
    const label =
        what === 'app'
            ? t(
                  'This app is locked and cannot be deleted. Unlock it to delete it.',
              )
            : t(
                  'This volume is locked and cannot be deleted. Unlock it to delete it.',
              );

    return (
        <Tooltip label={label} align={align}>
            <span
                role="img"
                aria-label={
                    what === 'app' ? t('Locked app') : t('Locked volume')
                }
                tabIndex={0}
                className="inline-flex items-center rounded-field text-base-content/70 outline-offset-2"
            >
                <Lock className="size-4" aria-hidden />
            </span>
        </Tooltip>
    );
}
