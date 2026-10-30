import { expect, test } from '@playwright/test';
import { shot, signIn, stepUp, type Session } from '../support/session';

/**
 * CRUD coverage step: New community on /communities, the Wards tab and
 * Edit on Leadership rows of an LGA page, and Administration › Hierarchy
 * (create a ward with step-up and a reason, then deactivate it). A State
 * Director has no hierarchy administration.
 */
test.describe.configure({ mode: 'serial' });

let dg: Session;
let admin: Session;
let sd: Session;

const LGA = 'Demo LGA LA-1-1';

test.beforeAll(async ({ browser }) => {
    dg = await signIn(browser, 'dg');
    admin = await signIn(browser, 'admin');
    sd = await signIn(browser, 'sd.lagos');
});

test.afterAll(async () => {
    await dg?.context.close();
    await admin?.context.close();
    await sd?.context.close();
});

test.afterEach(() => {
    dg.guard.assertClean();
    admin.guard.assertClean();
    sd.guard.assertClean();
});

test('New community on /communities asks for the LGA, then opens the form', async () => {
    const { page } = dg;
    await page.goto('/communities?lens=off');
    await page.locator('[data-test="new-community"]').click();
    const dialog = page.getByRole('dialog', { name: 'New community' });
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('LGA').selectOption({ label: LGA });
    await shot(page, '47-dg-new-community-lga');
    await dialog.getByRole('button', { name: 'Continue' }).click();
    await page.waitForURL(/\/lgas\/[0-9a-z]{26}\/communities\/create$/i);
});

test('an LGA page lists its wards and offers Edit on office rows', async () => {
    const { page } = dg;
    await page.goto('/lgas?lens=off');
    await page.getByRole('link', { name: LGA, exact: true }).first().click();
    await page.waitForURL(/\/lgas\/[0-9a-z]{26}/i);

    await page.getByRole('link', { name: 'Wards', exact: true }).click();
    await page.waitForURL(/tab=wards/);
    await expect(page.locator('table tbody tr')).not.toHaveCount(0);
    await shot(page, '48-dg-lga-wards');

    await page.getByRole('link', { name: 'Leadership', exact: true }).click();
    await page.waitForURL(/tab=leadership/);
    await page.locator('[data-test="edit-office"]').first().click();
    await page.waitForURL(/\/political-offices\/[0-9a-z]{26}\/edit$/i);
});

test('the DG creates a ward in Administration › Hierarchy and deactivates it', async () => {
    const { page } = dg;
    await page.goto('/dashboard?lens=off');
    await page
        .locator('[data-sidebar="group"]')
        .filter({ hasText: 'Administration' })
        .getByRole('link', { name: 'Hierarchy', exact: true })
        .click();
    await page.waitForURL(/\/system\/hierarchy$/);
    await expect(page.locator('tbody tr')).toHaveCount(37);

    await page.getByRole('link', { name: 'Lagos', exact: true }).click();
    await page.waitForURL(/\/system\/hierarchy\/states\/[0-9a-z]{26}$/i);
    await page.getByRole('link', { name: LGA, exact: true }).click();
    await page.waitForURL(/\/system\/hierarchy\/lgas\/[0-9a-z]{26}$/i);

    const code = `BC${Date.now().toString(36).toUpperCase()}`;
    await page.locator('[data-test="new-ward"]').click();
    const create = page.getByRole('dialog', { name: /New ward/ });
    await expect(
        create.getByText(/Codes are set when the entry is created/),
    ).toBeVisible();
    await create.getByLabel(/^Code/).fill(code);
    await create.getByLabel('Name').fill(`Browser check ward ${code}`);
    await create
        .getByLabel('Reason')
        .fill('Browser check: hierarchy administration');
    // The first attempt answers 428 (step-up required); the dialog then
    // asks for a code and the visit is retried.
    dg.guard.allow(428, /\/system\/hierarchy\/lgas\/[0-9a-z]{26}\/wards$/i);
    await create.getByRole('button', { name: 'Create ward' }).click();
    await stepUp(dg);
    await expect(create).toBeHidden();
    const row = page.locator('tr', { hasText: code });
    await expect(row).toContainText('Created on screen');
    await shot(page, '49-dg-hierarchy-ward-created');

    await page.locator(`[data-test="toggle-${code}"]`).click();
    const toggle = page.getByRole('dialog', { name: /Deactivate/ });
    await toggle.getByLabel('Reason').fill('Browser check: tidy up');
    await toggle.getByRole('button', { name: 'Deactivate' }).click();
    await expect(toggle).toBeHidden();
    await expect(row).toHaveCount(0);
    await page.getByLabel('Show deactivated wards').check();
    await expect(page.locator('tr', { hasText: code })).toContainText(
        'Deactivated',
    );
});

test('the super admin edits capitals from Hierarchy; a State Director has no access', async () => {
    await admin.page.goto('/system/hierarchy');
    await admin.page.locator('[data-test="edit-capital-LA"]').click();
    const dialog = admin.page.getByRole('dialog', { name: 'Capital of Lagos' });
    await expect(dialog.getByLabel('Capital')).toBeVisible();
    await shot(admin.page, '50-admin-hierarchy-capital');
    await dialog.getByRole('button', { name: 'Cancel' }).click();

    await sd.page.goto('/dashboard');
    await expect(
        sd.page
            .locator('[data-sidebar="menu-button"]')
            .filter({ hasText: 'Hierarchy' }),
    ).toHaveCount(0);
    sd.guard.allow(403, /\/system\/hierarchy$/);
    const response = await sd.page.goto('/system/hierarchy');
    expect(response?.status()).toBe(403);
});
