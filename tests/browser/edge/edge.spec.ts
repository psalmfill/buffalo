import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { signIn } from '../support/session';

/**
 * One edge mode, end to end, as the Director General:
 * - the site password (BASIC_AUTH=1) is asked before anything else;
 * - signing in works through the edge, over https;
 * - live notifications arrive over wss:// on the same host;
 * - the login history records the client's address (EDGE_EXPECT_IP), not a
 *   forged X-Forwarded-For header.
 *
 * EDGE_CMD runs a command in the environment's tools container
 * (scripts/uat/edge-check.sh sets it).
 */
const FORGED = '203.0.113.77';
const basicAuth = process.env.EDGE_BASIC_AUTH; // "user:password"
const httpCredentials = basicAuth
    ? {
          username: basicAuth.split(':')[0],
          password: basicAuth.slice(basicAuth.indexOf(':') + 1),
      }
    : undefined;

function artisan(code: string): string {
    const cmd = process.env.EDGE_CMD;
    if (!cmd) {
        throw new Error(
            'EDGE_CMD is not set (run through scripts/uat/edge-check.sh).',
        );
    }

    // Single-quoted for the shell: no $ or backslash expansion.
    const quoted = `'${code.replaceAll("'", "'\\''")}'`;

    return execFileSync(
        'sh',
        ['-c', `${cmd} php artisan tinker --execute ${quoted}`],
        {
            encoding: 'utf8',
        },
    ).trim();
}

test('the site password is asked first when it is on', async ({ browser }) => {
    test.skip(!basicAuth, 'BASIC_AUTH is off');
    // A browser without the password: nginx answers 401 before the application.
    const context = await browser.newContext();
    const response = await (await context.newPage()).goto('/login');
    expect(response?.status()).toBe(401);
    expect(response?.headers()['www-authenticate']).toContain('Basic');
    await context.close();
});

test('sign-in, live notifications over wss, and the client address in the login history', async ({
    browser,
    baseURL,
}) => {
    const host = new URL(baseURL ?? '').host;
    const session = await signIn(browser, process.env.EDGE_ACCOUNT ?? 'dg', {
        httpCredentials,
        // Forged: the edge must never let the browser choose its own address.
        extraHTTPHeaders: { 'X-Forwarded-For': FORGED },
    });
    const { page } = session;

    // The WebSocket goes to wss://<host>/app/... (live notifications).
    const sockets: string[] = [];
    const frames: string[] = [];
    page.on('websocket', (ws) => {
        sockets.push(ws.url());
        ws.on('framereceived', (frame) => frames.push(String(frame.payload)));
    });
    await page.goto('/dashboard');
    await expect
        .poll(() => sockets.find((url) => url.startsWith(`wss://${host}/app/`)))
        .toBeTruthy();
    await expect
        .poll(() =>
            frames.some((f) =>
                f.includes('pusher_internal:subscription_succeeded'),
            ),
        )
        .toBe(true);

    const marker = `Edge check ${Date.now()}`;
    artisan(
        `App\\Models\\User::where('email', '${session.who.email}')->first()->notify(new App\\Notifications\\SystemAlert('edge.check', 'warning', '${marker}', 'Live notification over wss.'));`,
    );
    await expect
        .poll(() => frames.some((f) => f.includes(marker)), { timeout: 30_000 })
        .toBe(true);

    const ip = artisan(
        `echo App\\Models\\LoginHistory::whereHas('user', fn ($q) => $q->where('email', '${session.who.email}'))->latest('id')->value('ip_address');`,
    );
    expect(ip).not.toBe(FORGED);
    if (process.env.EDGE_EXPECT_IP) {
        expect(ip).toBe(process.env.EDGE_EXPECT_IP);
    }
    console.log(`EDGE login history address: ${ip}`);

    session.guard.assertClean();
    await session.context.close();
});
