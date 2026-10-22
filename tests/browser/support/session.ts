import {
    expect,
    type Browser,
    type BrowserContext,
    type Page,
} from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { account, freshCode, type Account } from './accounts';
import { Guard } from './guard';

export const SHOTS = resolve(process.cwd(), '../../docs/uat/results/phase-11');
mkdirSync(SHOTS, { recursive: true });

export type Session = {
    who: Account;
    context: BrowserContext;
    page: Page;
    guard: Guard;
};

/** Signs in with the password and a TOTP code, as a tester would. */
export async function signIn(
    browser: Browser,
    local: string,
): Promise<Session> {
    const who = account(local);
    const context = await browser.newContext();
    const page = await context.newPage();
    const guard = await Guard.install(page);

    await page.goto('/login');
    await page.locator('#email').fill(who.email);
    await page.locator('#password').fill(who.password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL(/two-factor-challenge/);
    for (let attempt = 0; attempt < 2; attempt++) {
        await page
            .locator('input[data-input-otp]')
            .fill(await freshCode(who, attempt > 0));
        await page.getByRole('button', { name: 'Continue' }).click();
        const signedIn = await page
            .waitForURL((url) => !/login|two-factor/.test(url.pathname), {
                timeout: 10_000,
            })
            .then(
                () => true,
                () => false,
            );
        if (signedIn) {
            break;
        }
        // A code used moments ago (by an earlier run) is refused: replay protection.
        guard.allow(422, /two-factor-challenge/);
        await page.goto('/two-factor-challenge');
    }
    await expect(page).not.toHaveURL(/login|two-factor/);

    return { who, context, page, guard };
}

/** Answers the step-up dialog with a fresh code. */
export async function stepUp(session: Session): Promise<void> {
    const dialog = session.page.locator('[data-test="step-up-dialog"]');
    await expect(dialog).toBeVisible();
    await dialog
        .locator('input[data-input-otp]')
        .fill(await freshCode(session.who));
    await dialog.getByRole('button', { name: 'Confirm' }).click();
    await expect(dialog).toBeHidden();
}

/** A full-page screenshot in docs/uat/results/phase-11. */
export async function shot(page: Page, name: string): Promise<void> {
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await page.screenshot({
        path: resolve(SHOTS, `${name}.png`),
        fullPage: true,
    });
}

/**
 * Waits on My reports for the run just requested to complete (the status
 * updates live over Reverb, without a reload), downloads it and returns the
 * file's bytes.
 */
export async function downloadRun(
    page: Page,
    timeout = 120_000,
): Promise<Buffer> {
    await page.waitForURL(/\/reports\?run=/);
    const ulid = new URL(page.url()).searchParams.get('run');
    const link = page.locator(
        `tr[data-run="${ulid}"] [data-test="download-report"]`,
    );
    await expect(link).toBeVisible({ timeout });
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        link.click(),
    ]);
    const { readFileSync } = await import('node:fs');

    return readFileSync((await download.path()) as string);
}
