/**
 * UAT feedback endpoints (Phase 9B.5). Fixed paths rather than Wayfinder
 * helpers: the routes exist only in the UAT environment, so production
 * builds (which generate the helpers in production mode) have none.
 */
export const UAT_FEEDBACK = {
    store: '/uat/feedback',
    index: '/uat/feedback',
    export: '/uat/feedback/export',
} as const;
