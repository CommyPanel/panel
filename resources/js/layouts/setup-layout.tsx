import { Head, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { Stepper } from '@/components/apps/setup-steps';
import { Brand } from '@/components/brand';
import { HostBanner } from '@/components/host-banner';
import { ThemeMenu } from '@/components/theme-menu';
import { Toaster } from '@/components/ui/toaster';
import { useT } from '@/hooks/use-t';
import { notify } from '@/lib/toast';

/** The steps of the first run, in order; `step` is the index of the current one. */
const steps = (t: (key: string) => string) => [
    { id: 'admin', label: t('Admin account') },
    { id: 'https', label: t("Let's Encrypt") },
    { id: 'domain', label: t('Panel domain') },
];

type Props = {
    title: string;
    description?: string;
    step: number;
    children: ReactNode;
};

/**
 * The installation, start to finish, on one centered page: no sidebar and no
 * menu to wander off into, just the steps and a card for each. Used for creating
 * the first admin account and for the welcome steps that follow.
 */
export default function SetupLayout({
    title,
    description,
    step,
    children,
}: Props) {
    const { name, flash } = usePage().props;
    const { t } = useT();

    // The server's one-off messages ("saved", ...) arrive as flash data.
    useEffect(() => {
        if (flash?.status) notify.success(flash.status);
        if (flash?.warning) notify.warning(flash.warning);
    }, [flash]);

    return (
        <>
            <Head title={title} />
            <main className="min-h-screen bg-base-200 px-4 py-6 sm:py-10">
                <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
                    <div className="flex items-center justify-between">
                        <Brand name={name} />
                        <ThemeMenu />
                    </div>
                    <header>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        {description && (
                            <p className="mt-1 text-sm text-base-content/70">
                                {description}
                            </p>
                        )}
                    </header>
                    <Stepper steps={steps(t)} index={step} />
                    <HostBanner />
                    <div className="space-y-6">{children}</div>
                </div>
            </main>
            <Toaster />
        </>
    );
}
