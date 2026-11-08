import { Check, Monitor, Moon, Sun, SunDim } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Dropdown, DropdownItem } from '@/components/ui/dropdown';
import { useT } from '@/hooks/use-t';
import { useTheme } from '@/hooks/use-theme';
import { themeOptions } from '@/lib/theme';
import type { ThemePreference } from '@/lib/theme';

export const THEME_ICONS: Record<ThemePreference, LucideIcon> = {
    system: Monitor,
    light: Sun,
    dim: SunDim,
    dark: Moon,
};

export function ThemeMenu() {
    const { t } = useT();
    const [preference, setPreference] = useTheme();
    const Icon = THEME_ICONS[preference];

    return (
        <Dropdown
            label={t('Change theme')}
            trigger={<Icon className="size-5" />}
        >
            {(close) =>
                themeOptions(t).map((option) => {
                    const OptionIcon = THEME_ICONS[option.value];

                    return (
                        <DropdownItem
                            key={option.value}
                            radio
                            selected={preference === option.value}
                            onSelect={() => {
                                setPreference(option.value);
                                close();
                            }}
                        >
                            <OptionIcon className="size-4" />
                            <span className="flex-1">{option.label}</span>
                            {preference === option.value && (
                                <Check className="size-4" />
                            )}
                        </DropdownItem>
                    );
                })
            }
        </Dropdown>
    );
}
