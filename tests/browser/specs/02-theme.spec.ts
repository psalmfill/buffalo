import { expect, test } from '@playwright/test';
import { shot, signIn, type Session } from '../support/session';

/** Phase 11A.3: theme colours, the contrast check and reset (DG). */
test.describe.configure({ mode: 'serial' });

let dg: Session;

test.beforeAll(async ({ browser }) => {
    dg = await signIn(browser, 'dg');
});

test.afterAll(async () => {
    await dg?.context.close();
});

test.afterEach(() => {
    dg.guard.assertClean();
});

test('a passing palette colour is saved and applied', async () => {
    const { page } = dg;
    await page.goto('/system/theme');
    await page.locator('[id="chart.light.0"]').fill('#0f766e');
    await expect(
        page.locator('[data-test="ratio-chart.light.0"]'),
    ).toContainText('✓');
    await page.getByRole('button', { name: 'Save colours' }).click();
    await expect.soft(page.getByText('Theme colours saved.')).toBeVisible();
    const applied = await page.evaluate(() =>
        getComputedStyle(document.documentElement)
            .getPropertyValue('--chart-palette-1')
            .trim(),
    );
    expect(applied.toLowerCase()).toBe('#0f766e');
    await shot(page, '06-theme-saved');
});

test('a low-contrast colour is rejected with the nearest passing colour', async () => {
    const { page } = dg;
    await page.locator('[id="chart.light.1"]').fill('#ffe680');
    await expect(
        page.locator('[data-test="ratio-chart.light.1"]'),
    ).toContainText('needs 3:1');
    await page.getByRole('button', { name: 'Save colours' }).click();
    await expect(
        page
            .getByText(/contrast 1\.\d+:1 against the light card background/)
            .first(),
    ).toBeVisible();
    await expect(
        page
            .getByRole('button', { name: /Use the nearest passing colour/ })
            .first(),
    ).toBeVisible();
    await shot(page, '07-theme-rejected');
});

test('reset restores the defaults', async () => {
    const { page } = dg;
    await page.getByRole('button', { name: 'Discard changes' }).click();
    await page.getByRole('button', { name: 'Reset to defaults' }).click();
    await expect(
        page.getByRole('button', { name: 'Reset to defaults' }),
    ).toBeHidden();
    const applied = await page.evaluate(() =>
        getComputedStyle(document.documentElement)
            .getPropertyValue('--chart-palette-1')
            .trim(),
    );
    expect(applied.toLowerCase()).not.toBe('#0f766e');
    await shot(page, '08-theme-reset');
});
