import { defineConfig } from '@playwright/test';

/**
 * The edge mode check (scripts/uat/edge-check.sh): signs in through the
 * edge (BUFFALO's own nginx in direct mode, the host's nginx in proxy
 * mode), then checks live notifications over wss:// and the client address
 * in the login history. The test host name is mapped to this machine
 * (EDGE_RESOLVE=host:address), so no DNS is needed.
 */
const resolve = process.env.EDGE_RESOLVE ?? '';

export default defineConfig({
    testDir: './edge',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 120_000,
    expect: { timeout: 20_000 },
    reporter: [['list']],
    use: {
        baseURL: process.env.BUFFALO_URL,
        // Test certificates from a throwaway CA (never point this at a real system).
        ignoreHTTPSErrors: true,
        viewport: { width: 1280, height: 800 },
        launchOptions: {
            args: resolve
                ? [`--host-resolver-rules=MAP ${resolve.replace(':', ' ')}`]
                : [],
        },
    },
});
