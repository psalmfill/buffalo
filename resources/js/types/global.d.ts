import type { AppData } from '@/types/app';
import type { Auth } from '@/types/auth';

declare module 'react' {
    // Augmentation must repeat React's type parameter even though it is unused.
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            app: AppData;
            auth: Auth;
            sidebarOpen: boolean;
            boundaryAttribution: string | null;
            notifications: { unreadCount: number };
            badges: {
                coverageReview: number;
                immediateThreats: number;
                disseminations: number;
            };
            [key: string]: unknown;
        };
    }
}
