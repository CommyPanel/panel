import { Check } from 'lucide-react';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';

type Step = 'basics' | 'source' | 'settings' | 'domain';

/** A horizontal progress header; steps before `index` show as done. */
export function Stepper({
    steps,
    index,
}: {
    steps: { id: string; label: string }[];
    index: number;
}) {
    const { t } = useT();

    return (
        <ol
            className="mb-6 flex flex-wrap gap-x-6 gap-y-2"
            aria-label={t('Steps')}
        >
            {steps.map((step, i) => (
                <li
                    key={step.id}
                    aria-current={i === index ? 'step' : undefined}
                    className={cn(
                        'flex items-center gap-2 text-sm',
                        i === index
                            ? 'font-semibold text-base-content'
                            : 'text-base-content/60',
                    )}
                >
                    <span
                        className={cn(
                            'flex size-6 items-center justify-center rounded-full border text-xs',
                            i < index &&
                                'border-success bg-success text-success-content',
                            i === index &&
                                'border-primary bg-primary text-primary-content',
                            i > index && 'border-base-300',
                        )}
                    >
                        {i < index ? (
                            <Check className="size-3.5" aria-hidden />
                        ) : (
                            i + 1
                        )}
                    </span>
                    {step.label}
                </li>
            ))}
        </ol>
    );
}

/** The progress header of the app wizard. */
export function SetupSteps({
    current,
    source,
}: {
    current: Step;
    source: string;
}) {
    const { t } = useT();
    const steps: { id: Step; label: string }[] = [
        { id: 'basics', label: t('Basics') },
        {
            id: 'source',
            label:
                source === 'upload' ? t('Upload code') : t('Repository access'),
        },
        { id: 'settings', label: t('Settings') },
        { id: 'domain', label: t('Domain') },
    ];
    return (
        <Stepper
            steps={steps}
            index={steps.findIndex((step) => step.id === current)}
        />
    );
}
