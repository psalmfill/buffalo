import { expect, test, type Page } from '@playwright/test';
import {
    downloadRun,
    shot,
    signIn,
    stepUp,
    type Session,
} from '../support/session';

/**
 * Phase 11E: the Export dialog on several lists (Lagos DSD and DG), and a
 * sensitive list (persons of interest, Lagos SD) with step-up and a reason.
 */
test.describe.configure({ mode: 'serial' });

/** Opens the list's Export dialog, picks the format and exports. */
async function exportList(
    page: Page,
    list: string,
    format: 'xlsx' | 'csv' | 'pdf',
    name: string,
): Promise<void> {
    await page.locator(`[data-test="export-${list}"]`).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.locator('[data-test="export-rows"]')).toBeVisible();
    await dialog.getByLabel('Format', { exact: true }).selectOption(format);
    await shot(page, name);
    await dialog.locator('[data-test="export-submit"]').click();
}

test.describe('lists', () => {
    let dsd: Session;

    test.beforeAll(async ({ browser }) => {
        dsd = await signIn(browser, 'dsd.lagos');
    });
    test.afterAll(async () => dsd?.context.close());
    test.afterEach(() => dsd.guard.assertClean());

    test('communities as CSV, with markings first', async () => {
        const { page } = dsd;
        await page.goto('/communities');
        await exportList(
            page,
            'communities',
            'csv',
            '21-export-dialog-communities',
        );
        const file = (await downloadRun(page)).toString('utf8');
        expect(file).toMatch(/^\uFEFF?RESTRICTED/);
        expect(file).toContain('Generated for UAT Deputy State Director Lagos');
        await shot(page, '22-export-my-reports');
    });

    test('arrests as XLSX', async () => {
        const { page } = dsd;
        await page.goto('/arrests');
        await exportList(page, 'arrests', 'xlsx', '23-export-dialog-arrests');
        expect((await downloadRun(page)).subarray(0, 2).toString()).toBe('PK');
    });

    test('a deputy is not offered the persons export', async () => {
        const { page } = dsd;
        await page.goto('/persons');
        await expect(page.locator('[data-test="export-persons"]')).toHaveCount(
            0,
        );
    });
});

test.describe('the flashpoint register as a PDF table', () => {
    let dg: Session;

    test.beforeAll(async ({ browser }) => {
        dg = await signIn(browser, 'dg');
    });
    test.afterAll(async () => dg?.context.close());
    test.afterEach(() => dg.guard.assertClean());

    test('flashpoints filtered to Lagos', async () => {
        const { page } = dg;
        await page.goto('/flashpoints?lens=off');
        await exportList(
            page,
            'flashpoints',
            'pdf',
            '24-export-dialog-flashpoints',
        );
        expect((await downloadRun(page)).subarray(0, 5).toString()).toBe(
            '%PDF-',
        );
    });
});

test.describe('a sensitive list', () => {
    let sd: Session;

    test.beforeAll(async ({ browser }) => {
        sd = await signIn(browser, 'sd.lagos');
    });
    test.afterAll(async () => sd?.context.close());
    test.afterEach(() => sd.guard.assertClean());

    test('persons of interest: reason and step-up, no encrypted fields in the file', async () => {
        const { page, guard } = sd;
        guard.allow(428, /\/exports$/);
        await page.goto('/persons-of-interest?lens=off');
        await page.locator('[data-test="export-persons_of_interest"]').click();
        const dialog = page.getByRole('dialog');
        const notice = dialog.locator('[data-test="sensitive-notice"]');
        await expect(notice).toBeVisible();
        // The quota (3 sensitive exports a day per user) is enforced and
        // audited; it cannot be reset for reruns, so say so instead.
        test.skip(
            /to 0 more today/.test(await notice.innerText()),
            "The Lagos SD has used today's sensitive exports (3 a day): rerun this check tomorrow.",
        );
        await expect(
            dialog.locator('[data-test="export-submit"]'),
        ).toBeDisabled();
        await dialog
            .getByLabel('Reason for this export')
            .fill('Browser check: planning the joint patrol in Ward 4.');
        await dialog.getByLabel('Format', { exact: true }).selectOption('csv');
        await shot(page, '25-export-sensitive-dialog');
        await dialog.locator('[data-test="export-submit"]').click();
        await stepUp(sd);
        const file = (await downloadRun(page)).toString('utf8');
        expect(file).toContain('Checkperson');
        expect(file).toContain('30–39');
        expect(file).not.toContain('12312312312');
        expect(file).not.toContain('8030000077');
        expect(file).not.toContain('1991');
        await shot(page, '26-export-sensitive-done');
    });
});
