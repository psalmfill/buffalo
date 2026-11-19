import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import { editor } from '../support/editor';
import { shot, signIn, type Session } from '../support/session';

/**
 * Phase 11H: the Lagos State Director sends taskings through the one
 * picker, to one LGA (with a photo), to a district (the quick pick) and to
 * every LGA (Select all); the Lagos LGA chief sees the unread badge, opens,
 * acknowledges and completes with a photo; the director sees the progress
 * line update. (The dev data has no Akwa Ibom accounts.)
 */
test.describe.configure({ mode: 'serial' });

const FIXTURES = resolve(process.cwd(), 'fixtures');

let sd: Session;
let chief: Session;
let chiefLga = '';
let singleUrl = '';

test.beforeAll(async ({ browser }) => {
    sd = await signIn(browser, 'sd.lagos');
    chief = await signIn(browser, 'chief.lagos');

    await chief.page.goto('/dashboard');
    chiefLga = (
        (await chief.page.locator('h1').first().textContent()) ?? ''
    )
        .replace(/ LGA$/, '')
        .trim();
});

test.afterAll(async () => {
    await sd?.context.close();
    await chief?.context.close();
});

test.afterEach(() => {
    sd.guard.assertClean();
    chief.guard.assertClean();
});

async function confirmAndSend(page: Session['page'], sentence: RegExp) {
    await page.locator('[data-test="send-tasking"]').click();
    await expect(page.locator('[data-test="confirmation-text"]')).toHaveText(
        sentence,
    );
    await page.locator('[data-test="confirm-send"]').click();
    await page.waitForURL(/\/taskings\/[0-9a-z]{26}$/i);
    await expect(page.getByText(/Tasking TSK\/.+ sent\./)).toBeVisible();
}

test('the director sends to one LGA, with a long instruction and a photo', async () => {
    const { page } = sd;
    expect(chiefLga).not.toBe('');
    await page.goto('/dashboard');
    await expect(page.locator('[data-test="tasking-card"]')).toBeVisible();
    await page.locator('[data-test="new-tasking"]').first().click();
    await page.waitForURL(/\/taskings\/create$/);

    await page.locator(`[data-test="lga-${chiefLga}"]`).click();
    await expect(page.locator('[data-test="recipient-count"]')).toHaveText(
        /^1 of \d+ LGAs selected$/,
    );
    await page.getByLabel('Title (optional)').fill('Browser check: market roads');
    const paragraph =
        'Double the patrols on the market roads and report every incident to the State Director. ';
    await editor(page, 'Instruction').fill(
        paragraph.repeat(Math.ceil(20500 / paragraph.length)),
    );
    await page.getByLabel('Priority').selectOption('immediate');
    await page.locator('[data-test="save-draft"]').click();
    await page.waitForURL(/\/taskings\/[0-9a-z]{26}\/edit$/i);

    await page
        .locator('[data-test="tasking-attachments"] [data-test="attachment-input"]')
        .setInputFiles([resolve(FIXTURES, 'photo-1.jpg')]);
    await page.locator('[data-test="upload-attachments"]').click();
    await expect(
        page.getByRole('list', { name: 'Photos' }).locator('li'),
    ).toHaveCount(1);
    await shot(page, '60-sd-tasking-form');

    await confirmAndSend(
        page,
        new RegExp(`^Send to the ${chiefLga} LGA chief$`),
    );
    singleUrl = new URL(page.url()).pathname;
    await expect(page.locator('[data-test="progress-line"]')).toHaveText(
        '0 of 1 read · 0 acknowledged · 0 completed',
    );
});

test('the director sends to a senatorial district with the quick pick', async () => {
    const { page } = sd;
    await page.goto('/taskings/create');
    const district = page.locator('[data-test="district-pick"]');
    const value = await district
        .locator('option')
        .nth(1)
        .getAttribute('value');
    await district.selectOption(value ?? '');
    await expect(page.locator('[data-test="recipient-count"]')).not.toHaveText(
        /^0 of/,
    );
    await editor(page, 'Instruction').fill(
        'Hold a security meeting with community leaders this week.',
    );
    await confirmAndSend(page, /^Send to (the .+ LGA chief|\d+ LGA chiefs: .+)$/);
});

test('the director sends to every LGA with Select all', async () => {
    const { page } = sd;
    await page.goto('/taskings/create');
    // Select all covers the whole state, even with a search hiding rows.
    await page.locator('[data-test="recipient-search"]').fill(chiefLga);
    await page.locator('[data-test="select-all"]').click();
    const count = page.locator('[data-test="recipient-count"]');
    await expect(count).toHaveText(/^(\d+) of \1 LGAs selected$/);
    const total = (await count.textContent())?.split(' ')[0] ?? '';
    await editor(page, 'Instruction').fill(
        'Submit an inventory of checkpoints in your LGA by Friday.',
    );
    await shot(page, '61-sd-tasking-select-all');
    await confirmAndSend(
        page,
        new RegExp(`^Send to all ${total} LGA chiefs in Lagos`),
    );
    await expect(page.locator('[data-test="recipients-table"] tbody tr')).toHaveCount(
        Number(total),
    );
});

test('the LGA chief sees the unread badge, acknowledges and completes with a photo', async () => {
    const { page } = chief;
    await page.goto('/dashboard');
    const inbox = page.locator('[data-test="tasking-inbox"]');
    await expect(inbox).toBeVisible();
    await expect(inbox.locator('[data-test="unread-badge"]')).toBeVisible();
    await shot(page, '62-chief-messages-from-director');

    await page.goto(singleUrl);
    await expect(page.locator('[data-test="tasking-body"]')).toContainText(
        'Double the patrols',
    );
    // Never the other LGAs.
    await expect(page.locator('[data-test="recipients"]')).toHaveCount(0);
    await page.locator('[data-test="acknowledge"]').click();
    await expect(page.locator('[data-test="my-status"]')).toHaveText(
        'Acknowledged',
    );

    await page
        .locator('[data-test="response-attachments"] [data-test="attachment-input"]')
        .setInputFiles([resolve(FIXTURES, 'photo-2.jpg')]);
    await page.locator('[data-test="upload-attachments"]').click();
    await expect(
        page
            .locator('[data-test="response-attachments"]')
            .getByRole('list', { name: 'Photos' })
            .locator('li'),
    ).toHaveCount(1);

    await editor(page, 'Report back (required to mark completed)').fill(
        'Patrols doubled on both market roads; no incidents since Monday.',
    );
    await page.locator('[data-test="mark-completed"]').click();
    await expect(page.locator('[data-test="my-status"]')).toHaveText(
        'Completed',
    );
    await expect(page.locator('[data-test="report-back"]')).toContainText(
        'Patrols doubled',
    );
    await shot(page, '63-chief-tasking-completed');
});

test('the director sees the progress line update and reads the report back', async () => {
    const { page } = sd;
    await page.goto(singleUrl);
    await expect(page.locator('[data-test="progress-line"]')).toHaveText(
        '1 of 1 read · 1 acknowledged · 1 completed',
    );
    await page.locator('[data-test="recipient-row"]').first().click();
    await expect(page.locator('[data-test="selected-recipient"]')).toContainText(
        'Patrols doubled',
    );
    await shot(page, '64-sd-tasking-progress');
});
