import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import { editor } from '../support/editor';
import { shot, signIn, type Session } from '../support/session';

/**
 * Phase 11G: the Incidents menu without "Report incident"; an LGA chief
 * writes a long security report, attaches two photos and a video and
 * submits it with nothing else filled; the State Director reviews it; the
 * person-of-interest form with the new details; a search by a person's
 * second phone number finds them.
 */
test.describe.configure({ mode: 'serial' });

const FIXTURES = resolve(process.cwd(), 'fixtures');

let dg: Session;
let sd: Session;
let chief: Session;
let reportUrl = '';

test.beforeAll(async ({ browser }) => {
    dg = await signIn(browser, 'dg');
    sd = await signIn(browser, 'sd.lagos');
    chief = await signIn(browser, 'chief.lagos');
});

test.afterAll(async () => {
    await dg?.context.close();
    await sd?.context.close();
    await chief?.context.close();
});

test.afterEach(() => {
    dg.guard.assertClean();
    sd.guard.assertClean();
    chief.guard.assertClean();
});

test('the Incidents menu has no Report incident item; the list has the button', async () => {
    const { page } = dg;
    await page.goto('/dashboard?lens=off');
    const group = page
        .locator('[data-sidebar="group"]')
        .filter({ hasText: 'Arrests' });
    await expect(group.locator('[data-sidebar="menu-button"]')).toHaveText([
        /Incidents/,
        /Incident map/,
        /Arrests/,
    ]);
    await page.goto('/incidents?lens=off');
    await expect(
        page.getByRole('link', { name: 'Report incident' }),
    ).toBeVisible();
});

test('an LGA chief writes a long report, attaches two photos and a video, and submits it', async () => {
    const { page } = chief;
    await page.goto('/security-reports/create');
    await expect(
        page.getByRole('heading', { name: 'New security report' }),
    ).toBeVisible();

    // More than 20,000 characters, and nothing else filled in.
    const paragraph =
        'Patrols covered the markets and the motor parks; two checkpoints were unmanned at night. ';
    const text = paragraph.repeat(Math.ceil(20500 / paragraph.length));
    await editor(page, 'Report').fill(text);
    await page.getByRole('button', { name: 'Save draft' }).click();
    await page.waitForURL(/\/security-reports\/[0-9a-z]{26}$/i);
    reportUrl = new URL(page.url()).pathname;

    await page
        .locator('[data-test="attachment-input"]')
        .setInputFiles([
            resolve(FIXTURES, 'photo-1.jpg'),
            resolve(FIXTURES, 'photo-2.jpg'),
            resolve(FIXTURES, 'clip.mp4'),
        ]);
    await expect(page.locator('[data-test="pending-attachment"]')).toHaveCount(
        3,
    );
    await page.locator('[data-test="upload-attachments"]').click();
    await expect(
        page.getByRole('list', { name: 'Photos' }).locator('li'),
    ).toHaveCount(2);
    await expect(
        page.getByRole('list', { name: 'Videos' }).locator('li'),
    ).toHaveCount(1);
    await shot(page, '51-chief-security-report-attachments');

    await page.getByRole('link', { name: 'Edit draft' }).click();
    await page.getByRole('button', { name: 'Submit for review' }).click();
    await page.getByRole('button', { name: 'Confirm submit' }).click();
    await expect(page.getByText('Security report submitted.')).toBeVisible();
});

test('the State Director reviews the report', async () => {
    const { page } = sd;
    await page.goto(reportUrl);
    await expect(
        page.getByText(/Patrols covered the markets/).first(),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Mark reviewed (locks the report)' })
        .click();
    await expect(page.getByText(/This report is locked/)).toBeVisible();
    await shot(page, '52-sd-security-report-reviewed');
});

test('the POI form records the new details; sensitive ones stay in their own card', async () => {
    const { page } = sd;
    await page.goto('/persons-of-interest');
    await page
        .getByRole('link', { name: /Checkperson/ })
        .first()
        .click();
    await page.waitForURL(/\/persons\/[0-9a-z]{26}/i);
    await page.goto(`${new URL(page.url()).pathname}?tab=poi`);
    await page.getByRole('link', { name: 'Edit details' }).click();

    await page.getByLabel('Height').first().fill('175');
    await page.getByLabel('Marital status').selectOption('married');
    await page.getByLabel('Colour of hair').selectOption('black');
    await page.getByLabel('Colour of eyes').selectOption('dark_brown');
    await page.getByLabel('Hometown').fill('Ilesa');
    await page
        .getByLabel('Political affiliations')
        .fill('Browser check affiliation');
    await page.getByRole('button', { name: 'Save' }).click();
    await page.waitForURL(/\/persons\/[0-9a-z]{26}/i);
    await page.goto(`${new URL(page.url()).pathname}?tab=poi`);

    await expect(page.locator('[data-test="poi-personal"]')).toContainText(
        '175 cm',
    );
    await expect(page.locator('[data-test="poi-personal"]')).toContainText(
        'Ilesa',
    );
    await expect(page.locator('[data-test="poi-physical"]')).toContainText(
        'Dark brown',
    );
    await expect(page.locator('[data-test="poi-sensitive"]')).toContainText(
        'Browser check affiliation',
    );
    await expect(page.locator('[data-test="poi-personal"]')).not.toContainText(
        'Browser check affiliation',
    );
    await shot(page, '53-sd-poi-details');
});

test('a search by the second phone number finds the person', async () => {
    const { page } = sd;
    await page.goto('/persons');
    await page.getByLabel('Name, NIN or phone number').fill('0803 000 0078');
    await page.keyboard.press('Enter');
    await expect(
        page.getByRole('link', { name: /Checkperson/ }).first(),
    ).toBeVisible();
});
