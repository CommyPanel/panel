import { useT } from '@/hooks/use-t';
import { useTheme } from '@/hooks/use-theme';
import { themeOptions } from '@/lib/theme';
import { cn } from '@/lib/utils';
import { THEME_ICONS } from '@/components/theme-menu';

/** Colour swatches shown on each option: page, card, text, accent. */
const SWATCHES = {
    system: null,
    light: ['#f5f5f4', '#ffffff', '#1c1917', '#c2410c'],
    dim: ['#0c0a09', '#1c1917', '#f5f5f4', '#f97316'],
    dark: ['#000000', '#0c0a09', '#f5f5f4', '#f97316'],
} as const;

export function ThemePicker() {
    const { t } = useT();
    const [preference, setPreference] = useTheme();

    return (
        <div
            role="radiogroup"
            aria-label={t('Theme')}
            className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
            {themeOptions(t).map((option) => {
                const Icon = THEME_ICONS[option.value];
                const swatches = SWATCHES[option.value];
                const selected = preference === option.value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => setPreference(option.value)}
                        className={cn(
                            'flex flex-col gap-3 rounded-box border border-base-300 p-4 text-left transition-colors hover:border-primary/60',
                            selected && 'border-primary ring-2 ring-primary/30',
                        )}
                    >
                        <span className="flex h-12 w-full overflow-hidden rounded-field border border-base-300">
                            {swatches ? (
                                swatches.map((color) => (
                                    <span
                                        key={color}
                                        className="flex-1"
                                        style={{ backgroundColor: color }}
                                    />
                                ))
                            ) : (
                                <>
                                    <span
                                        className="flex-1"
                                        style={{ backgroundColor: '#ffffff' }}
                                    />
                                    <span
                                        className="flex-1"
                                        style={{ backgroundColor: '#1c1917' }}
                                    />
                                </>
                            )}
                        </span>
                        <span className="flex items-center gap-2 font-medium">
                            <Icon className="size-4" />
                            {option.label}
                        </span>
                        <span className="text-sm text-base-content/70">
                            {option.description}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
