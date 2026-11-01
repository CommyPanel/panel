import { Link, usePage } from '@inertiajs/react';
import { CircleArrowUp } from 'lucide-react';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Appears in the top bar while a newer CommyPanel is available, and leads to
 * the page that explains how to upgrade. It reads what the panel already
 * knows; it never contacts GitHub itself.
 */
export function UpdateBadge() {
    const { update } = usePage().props;

    if (!update) return null;

    const label = `A newer version of CommyPanel is available (${update.latest}). You are running ${update.current}.`;

    return (
        <Tooltip label={`A newer version is available (${update.latest})`}>
            <Link
                href="/settings/updates"
                aria-label={label}
                className="btn gap-2 btn-soft btn-sm text-primary btn-primary"
            >
                <span className="relative flex size-4 items-center justify-center">
                    <span
                        aria-hidden
                        className="absolute inline-flex size-full animate-ping rounded-full bg-primary/40 motion-reduce:animate-none"
                    />
                    <CircleArrowUp className="relative size-4" aria-hidden />
                </span>
                <span className="hidden sm:inline">Update</span>
            </Link>
        </Tooltip>
    );
}
