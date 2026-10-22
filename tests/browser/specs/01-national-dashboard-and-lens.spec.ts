import { expect, test } from '@playwright/test';
import { shot, signIn, type Session } from '../support/session';

/**
 * Phase 11A.2: the DG's state grid, a state's dashboard, and the state lens
 * across incidents, the incident map and analytics.
 */
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

test('the national dashboard shows the state grid', async () => {
    const { page } = dg;
    await page.goto('/dashboard?lens=off');
    const cards = page.locator('[data-test="state-card"]');
    await expect(cards.first()).toBeVisible();
    expect(await cards.count()).toBeGreaterThanOrEqual(2);
    await expect(
        cards.filter({ has: page.getByRole('heading', { name: 'Lagos' }) }),
    ).toHaveCount(1);
    await shot(page, '01-dg-state-grid');
});

test("a state card opens that state's dashboard", async () => {
    const { page } = dg;
    await page
        .locator('[data-test="state-card"]', { hasText: 'Lagos' })
        .first()
        .click();
    await page.waitForURL(/\/dashboard\/states\//);
    await expect(
        page.getByRole('heading', { name: /Lagos/ }).first(),
    ).toBeVisible();
    await shot(page, '02-dg-lagos-dashboard');
});

test('the state lens pre-sets incidents, the incident map and analytics', async () => {
    const { page } = dg;
    await page.goto('/incidents');
    const picker = page.locator('[data-test="lens-picker"]');
    const lagos = await picker
        .locator('option', { hasText: 'Lagos' })
        .getAttribute('value');
    await Promise.all([
        page.waitForURL(/.*/),
        picker.selectOption(lagos as string),
    ]);
    await expect(page.locator('[data-test="lens-chip"]')).toContainText(
        'Lagos',
    );

    await page.goto('/incidents');
    await expect(page).toHaveURL(/[?&]state=/);
    await shot(page, '03-lens-incidents');

    await page.goto('/incidents/map');
    await expect(page).toHaveURL(/[?&]state=/);
    await expect(page.locator('.leaflet-container').first()).toBeVisible();
    await shot(page, '04-lens-incident-map');

    await page.goto('/analytics');
    await expect(page).toHaveURL(/[?&]state=|lens/);
    await shot(page, '05-lens-analytics');

    await page
        .locator('[data-test="lens-chip"]')
        .getByRole('button', { name: /Clear lens/ })
        .click();
    await expect(page.locator('[data-test="lens-chip"]')).toBeHidden();
});
