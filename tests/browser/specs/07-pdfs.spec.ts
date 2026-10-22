import { expect, test, type Page } from '@playwright/test';
import {
    downloadRun,
    shot,
    signIn,
    stepUp,
    type Session,
} from '../support/session';

/** Phase 11E: flashpoint, organization site, arrest and person PDFs (Lagos SD). */
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

async function printAndDownload(page: Page, name: string): Promise<void> {
    await page.locator('[data-test="print-record"]').click();
    const pdf = await downloadRun(page);
    expect(pdf.subarray(0, 5).toString()).toBe('%PDF-');
    await shot(page, name);
}

test('a flashpoint PDF with its location sketch', async () => {
    const { page } = sd;
    await page.goto('/flashpoints?q=Browser%20Check');
    await page
        .getByRole('link', { name: 'UAT Browser Check Junction' })
        .first()
        .click();
    await page.waitForURL(/\/flashpoints\/[0-9a-z]{26}/);
    await printAndDownload(page, '27-pdf-flashpoint');
});

test('an organization site PDF', async () => {
    const { page } = sd;
    await page.goto(`/organizations?q=${encodeURIComponent('Browser Check')}`);
    await page
        .getByRole('link', { name: 'UAT Browser Check Haulage Ltd' })
        .click();
    await page
        .getByRole('link', { name: 'UAT Browser Check Depot' })
        .first()
        .click();
    await printAndDownload(page, '28-pdf-site');
});

test('an arrest PDF', async () => {
    const { page } = sd;
    await page.goto('/arrests');
    await page.locator('table tbody tr a[href*="/arrests/"]').first().click();
    await page.waitForURL(/\/arrests\/[0-9a-z]{26}/);
    await printAndDownload(page, '29-pdf-arrest');
});

test('a person PDF asks for the code first', async () => {
    const { page, guard } = sd;
    guard.allow(428, /\/reports$/);
    await page.goto('/persons-of-interest?lens=off');
    await page
        .getByRole('link', { name: /Checkperson/ })
        .first()
        .click();
    await page.waitForURL(/\/persons\/[0-9a-z]{26}/);
    await page.locator('[data-test="print-record"]').click();
    await stepUp(sd);
    const pdf = await downloadRun(page);
    expect(pdf.subarray(0, 5).toString()).toBe('%PDF-');
    await shot(page, '30-pdf-person');
});
