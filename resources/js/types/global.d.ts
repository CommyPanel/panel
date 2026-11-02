import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            flash: { status: string | null; warning: string | null };
            /** Whether the agent and Docker answer; null when signed out. */
            hostHealth: {
                agent: boolean;
                docker: boolean;
                disk: {
                    level: 'ok' | 'warn' | 'critical';
                    used_percent: number;
                    free_bytes: number;
                    total_bytes: number;
                } | null;
            } | null;
            /** Set while a newer release exists and the user can see the server; null otherwise. */
            update: { current: string; latest: string } | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
