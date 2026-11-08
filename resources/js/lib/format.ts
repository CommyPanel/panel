import { getLocale, t } from '@/lib/i18n';

export function formatDate(iso: string | null): string {
    return iso
        ? new Intl.DateTimeFormat(getLocale(), {
              dateStyle: 'short',
              timeStyle: 'medium',
          }).format(new Date(iso))
        : '-';
}

/** "42s", "3m 05s" in English; the language's own short units elsewhere. */
export function formatDuration(seconds: number | null): string {
    if (seconds === null) return '-';

    const unit = (value: number, name: 'second' | 'minute', digits = 1) =>
        new Intl.NumberFormat(getLocale(), {
            style: 'unit',
            unit: name,
            unitDisplay: 'narrow',
            minimumIntegerDigits: digits,
        }).format(value);

    if (seconds < 60) return unit(seconds, 'second');

    return `${unit(Math.floor(seconds / 60), 'minute')} ${unit(seconds % 60, 'second', 2)}`;
}

/** "just now", "5 minutes ago", "3 days ago"; falls back to the date after a month. */
export function timeAgo(iso: string | null): string {
    if (!iso) return '-';

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 45) return t('just now');

    if (seconds >= 30 * 86400) {
        return new Intl.DateTimeFormat(getLocale()).format(new Date(iso));
    }

    const units: [number, Intl.RelativeTimeFormatUnit][] = [
        [60, 'minute'],
        [3600, 'hour'],
        [86400, 'day'],
    ];
    const [size, unit] =
        seconds < 3600 ? units[0] : seconds < 86400 ? units[1] : units[2];

    return new Intl.RelativeTimeFormat(getLocale(), { numeric: 'auto' }).format(
        -Math.floor(seconds / size),
        unit,
    );
}

/** "1.4 GB", "320 MB". */
export function formatBytes(bytes: number): string {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const number = (value: number, digits: number) =>
        new Intl.NumberFormat(getLocale(), {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits,
        }).format(value);

    if (bytes <= 0) return `${number(0, 0)} B`;

    const index = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );
    const value = bytes / 1024 ** index;

    return `${number(value, value >= 10 || index === 0 ? 0 : 1)} ${units[index]}`;
}

/** "3 days 4 hours", "12 minutes". */
export function formatUptime(seconds: number): string {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const part = (n: number, unit: 'day' | 'hour' | 'minute') =>
        new Intl.NumberFormat(getLocale(), {
            style: 'unit',
            unit,
            unitDisplay: 'long',
        }).format(n);

    if (days > 0) return `${part(days, 'day')} ${part(hours, 'hour')}`;
    if (hours > 0) return `${part(hours, 'hour')} ${part(minutes, 'minute')}`;

    return part(minutes, 'minute');
}
