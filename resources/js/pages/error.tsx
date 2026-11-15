import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    FileQuestion,
    Home,
    LogIn,
    Lock,
    RefreshCw,
    ServerCrash,
    ShieldAlert,
    TriangleAlert,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import { Brand } from '@/components/brand';
import { LanguageMenu } from '@/components/language-menu';
import { ThemeMenu } from '@/components/theme-menu';
import { useT } from '@/hooks/use-t';

type Props = { status: number };

type Copy = { icon: LucideIcon; title: string; message: string };

function copyFor(status: number, t: (key: string) => string): Copy {
    switch (status) {
        case 401:
            return {
                icon: LogIn,
                title: t('Sign in to continue'),
                message: t('You need to sign in to see this page.'),
            };
        case 403:
            return {
                icon: ShieldAlert,
                title: t('You do not have access'),
                message: t(
                    'Your account is not allowed to do this. If you need it, ask an administrator to change your role.',
                ),
            };
        case 404:
            return {
                icon: FileQuestion,
                title: t('Page not found'),
                message: t(
                    'The page you are looking for does not exist, or it was moved or removed.',
                ),
            };
        case 405:
            return {
                icon: Lock,
                title: t('That is not allowed here'),
                message: t(
                    'This address does not accept that kind of request. Go back and use the buttons on the page.',
                ),
            };
        case 408:
        case 504:
            return {
                icon: TriangleAlert,
                title: t('That took too long'),
                message: t(
                    'The server did not answer in time. Wait a moment and try again.',
                ),
            };
        case 500:
            return {
                icon: ServerCrash,
                title: t('Something went wrong'),
                message: t(
                    'The panel hit an unexpected problem. It was logged on the server. Try again, and if it keeps happening, look at the panel log.',
                ),
            };
        case 502:
        case 503:
            return {
                icon: Wrench,
                title: t('Back in a moment'),
                message: t(
                    'The panel is restarting or being updated. This usually takes less than a minute. Try again shortly.',
                ),
            };
        default:
            return {
                icon: TriangleAlert,
                title: t('The request could not be completed'),
                message: t(
                    'Something stopped this request. Go back and try again.',
                ),
            };
    }
}

/**
 * An error page in the look of the panel. It arrives as a normal page, so a failed visit shows it
 * in place instead of the browser's grey overlay, and it works for people who are not signed in.
 */
export default function ErrorPage({ status }: Props) {
    const { t } = useT();
    const { name, auth } = usePage().props;
    const signedIn = Boolean(auth?.user);
    const { icon: Icon, title, message } = copyFor(status, t);
    const retry = status >= 500 || status === 408;

    return (
        <>
            <Head title={title} />
            <main className="flex min-h-screen flex-col bg-base-200 px-4 py-6 sm:py-10">
                <div className="mx-auto flex w-full max-w-2xl items-center justify-between">
                    <Brand name={name} />
                    <div className="flex items-center">
                        <LanguageMenu />
                        <ThemeMenu />
                    </div>
                </div>
                <div className="mx-auto flex w-full max-w-2xl flex-1 items-center py-10">
                    <div className="w-full rounded-box border border-base-300 bg-base-100 p-8 text-center sm:p-12">
                        <div className="mx-auto flex size-16 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Icon className="size-8" aria-hidden />
                        </div>
                        <p className="mt-6 text-sm font-semibold tracking-widest text-primary">
                            {t('Error :status', { status: String(status) })}
                        </p>
                        <h1 className="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">
                            {title}
                        </h1>
                        <p className="mx-auto mt-3 max-w-md text-base-content/70">
                            {message}
                        </p>
                        <div className="mt-8 flex flex-wrap justify-center gap-3">
                            {retry && (
                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    onClick={() => window.location.reload()}
                                >
                                    <RefreshCw className="size-4" aria-hidden />
                                    {t('Try again')}
                                </button>
                            )}
                            {signedIn ? (
                                <Link
                                    href={DashboardController.url()}
                                    className={
                                        retry
                                            ? 'btn btn-soft'
                                            : 'btn btn-primary'
                                    }
                                >
                                    <Home className="size-4" aria-hidden />
                                    {t('Go to the dashboard')}
                                </Link>
                            ) : (
                                <Link
                                    href="/login"
                                    className={
                                        retry
                                            ? 'btn btn-soft'
                                            : 'btn btn-primary'
                                    }
                                >
                                    <LogIn className="size-4" aria-hidden />
                                    {t('Sign in')}
                                </Link>
                            )}
                            <button
                                type="button"
                                className="btn btn-text"
                                onClick={() => window.history.back()}
                            >
                                <ArrowLeft className="size-4" aria-hidden />
                                {t('Go back')}
                            </button>
                        </div>
                    </div>
                </div>
            </main>
        </>
    );
}
