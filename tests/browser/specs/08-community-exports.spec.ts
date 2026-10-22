import { expect, test } from '@playwright/test';
import { downloadRun, shot, signIn, type Session } from '../support/session';

/**
 * The community page's tabs (religious institutions, vulnerable figures,
 * security notes) export their lists for that community (Lagos DSD).
 */
test.describe.configure({ mode: 'serial' });

let dsd: Session;

test.beforeAll(async ({ browser }) => {
    dsd = await signIn(browser, 'dsd.lagos');
});

test.afterAll(async () => {
    await dsd?.context.close();
});

test.afterEach(() => {
    dsd.guard.assertClean();
});

test('each community tab has an Export button, and the institutions export runs', async () => {
    const { page } = dsd;
    await page.goto('/communities');
    await page
        .locator('table tbody tr a[href*="/communities/"]')
        .first()
        .click();
    await page.waitForURL(/\/communities\/[0-9a-z]{26}/);
    const community = page.url().split('?')[0];

    for (const [tab, list] of [
        ['vulnerable_population', 'vulnerable_population'],
        ['security_notes', 'security_notes'],
        ['religious_institutions', 'religious_institutions'],
    ]) {
        await page.goto(`${community}?tab=${tab}`);
        await expect(
            page.locator(`[data-test="export-${list}"]`),
        ).toBeVisible();
    }
    await shot(page, '31-community-tab-export');

    await page.locator('[data-test="export-religious_institutions"]').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.locator('[data-test="export-rows"]')).toBeVisible();
    await dialog.getByLabel('Format', { exact: true }).selectOption('csv');
    await dialog.locator('[data-test="export-submit"]').click();
    const file = (await downloadRun(page)).toString('utf8');
    expect(file).toContain('Religious institutions');
});
