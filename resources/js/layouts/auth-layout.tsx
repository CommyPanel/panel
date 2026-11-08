import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Brand } from '@/components/brand';
import { LanguageMenu } from '@/components/language-menu';
import { ThemeMenu } from '@/components/theme-menu';

type Props = { title: string; description?: string; children: ReactNode };

export default function AuthLayout({ title, description, children }: Props) {
    const { name } = usePage().props;

    return (
        <>
            <Head title={title} />
            <main className="flex min-h-screen flex-col items-center justify-center gap-6 bg-base-200 p-4">
                <div className="flex w-full max-w-md justify-end">
                    <LanguageMenu />
                    <ThemeMenu />
                </div>
                <div className="w-full max-w-md rounded-box border border-base-300 bg-base-100 p-6 sm:p-8">
                    <Brand name={name} className="mb-6" />
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {title}
                    </h1>
                    {description && (
                        <p className="mt-1 text-sm text-base-content/70">
                            {description}
                        </p>
                    )}
                    <div className="mt-6">{children}</div>
                </div>
            </main>
        </>
    );
}
