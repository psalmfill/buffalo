import type { AppData } from '@/types/app';
import type { Auth } from '@/types/auth';
import type { Lens } from '@/types/lens';

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
            /** Null for users without a lens control (Phase 11A.2). */
            lens: Lens | null;
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
