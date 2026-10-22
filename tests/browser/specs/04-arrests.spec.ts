import { expect, test, type Page } from '@playwright/test';
import { shot, signIn, type Session } from '../support/session';

/** Phase 11C: "Arrest made" from an incident, and a standalone arrest (Lagos SD). */
test.describe.configure({ mode: 'serial' });

let sd: Session;

test.beforeAll(async ({ browser }) => {
    sd = await signIn(browser, 'sd.lagos');
});

test.afterAll(async () => {
    await sd?.context.close();
});

test.afterEach(() => {
    sd.guard.assertClean();
});

/** Fills the time and one unidentified person with an offence, then records the arrest. */
async function completeArrest(page: Page, description: string): Promise<void> {
    await page
        .locator('#arrested_at')
        .fill(new Date(Date.now() - 3600_000).toISOString().slice(0, 16));
    if ((await page.locator('#agency').inputValue()) === '') {
        await page.locator('#agency').selectOption({ index: 1 });
    }
    const person = page.locator('[aria-label^="Arrested person"]').first();
    if (!(await person.isVisible())) {
        await page.getByRole('button', { name: /Unidentified person/ }).click();
    }
    await page
        .getByLabel('Description of the unidentified person')
        .first()
        .fill(description);
    await page.getByLabel('Offence').first().selectOption({ index: 1 });
    await page.getByRole('button', { name: 'Record arrest' }).click();
    await page.waitForURL(/\/arrests\/[0-9a-z]{26}(\?|$)/);
    await expect(page.getByText(/ARR\/LA\/\d{4}\/\d{5}/).first()).toBeVisible();
}

test('"Arrest made" on an incident opens a pre-filled arrest form', async () => {
    const { page } = sd;
    await page.goto('/incidents?statuses=verified');
    await page.locator('table tbody tr a[href*="/incidents/"]').first().click();
    await page.waitForURL(/\/incidents\/[0-9a-z]{26}/);
    await page.locator('[data-test="arrest-made"]').click();
    await page.waitForURL(/\/arrests\/create\?incident=/);
    await expect(page.locator('#lga')).not.toHaveValue('');
    await shot(page, '12-arrest-made-prefilled');

    await completeArrest(page, 'Browser check: man in a green shirt');
    await expect(page.getByText(/BUF\/|DEMO\//).first()).toBeVisible();
    await shot(page, '13-arrest-from-incident');
});

test('a standalone arrest is recorded from the arrests list', async () => {
    const { page } = sd;
    await page.goto('/arrests');
    await page.getByRole('link', { name: /New arrest/ }).click();
    await page.waitForURL(/\/arrests\/create/);
    await page.locator('#lga').selectOption({ index: 1 });
    await completeArrest(page, 'Browser check: woman with a blue bag');
    await expect(page).toHaveURL(/\/arrests\/[0-9a-z]{26}/);
    await shot(page, '14-arrest-standalone');
});
