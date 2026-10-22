import { defineConfig } from '@playwright/test';

/**
 * The Phase 11 browser check (make browser-check): a scripted pass over the
 * UAT environment (make uat-up) in Chromium. Serial, one worker: the
 * accounts' single-session policy and TOTP replay protection allow one
 * signed-in browser per account at a time.
 */
export default defineConfig({
    testDir: './specs',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 120_000,
    expect: { timeout: 15_000 },
    reporter: [['list'], ['json', { outputFile: 'test-results/report.json' }]],
    use: {
        baseURL: process.env.BUFFALO_URL ?? 'https://localhost:19443',
        // The UAT certificate is self-signed (never point this at a real system).
        ignoreHTTPSErrors: true,
        acceptDownloads: true,
        viewport: { width: 1440, height: 900 },
        trace: 'retain-on-failure',
    },
});
