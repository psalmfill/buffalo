import { expect, test } from '@playwright/test';
import { editor, pasteHtml, WORD_PASTE } from '../support/editor';
import { shot, signIn, type Session } from '../support/session';

/**
 * Phase 11B: the rich text editor on an incident report and a follow-up,
 * including pasted Word-style content (kept: bold, lists; dropped: styles,
 * scripts, images).
 */
test.describe.configure({ mode: 'serial' });

let chief: Session;
let incidentUrl = '';

test.beforeAll(async ({ browser }) => {
    chief = await signIn(browser, 'chief.lagos');
});

test.afterAll(async () => {
    await chief?.context.close();
});

test.afterEach(() => {
    chief.guard.assertClean();
});

test('an incident description is written with the toolbar and a formatted paste', async () => {
    const { page } = chief;
    await page.goto('/incidents/create');
    const type = page.locator('#incident_type');
    await type.selectOption({ index: 1 });
    await page.locator('#severity').selectOption('medium');
    await page.locator('#title').fill('Browser check: market fight');

    const description = editor(page, 'Description');
    await description.click();
    await page.keyboard.type('Typed opening sentence. ');
    await page
        .getByRole('toolbar', { name: 'Formatting' })
        .first()
        .getByRole('button', { name: /Bold/ })
        .click();
    await page.keyboard.type('Bold from the toolbar.');
    await page.keyboard.press('Enter');
    await pasteHtml(
        description,
        WORD_PASTE,
        'Pasted bold and red Calibri text',
    );
    await expect(
        description.locator('strong', { hasText: 'Pasted bold' }),
    ).toBeVisible();
    await expect(
        description.locator('li', { hasText: 'Second pasted point' }),
    ).toBeVisible();
    expect(await description.innerHTML()).not.toMatch(
        /style=|<script|<img|class="Mso/,
    );
    await shot(page, '09-rich-text-incident-editor');

    const now = new Date(Date.now() - 2 * 3600_000 + 3600_000);
    await page.locator('#occurred_at').fill(now.toISOString().slice(0, 16));
    await page.getByRole('button', { name: 'Save and submit' }).click();
    await page.waitForURL(/\/incidents\/[0-9a-z]{26}(\?|$)/);
    incidentUrl = page.url().split('?')[0];
    const confirm = page.getByRole('dialog', { name: 'Submit this incident' });
    await confirm.getByRole('button', { name: 'Submit' }).click();
    await expect(confirm).toBeHidden();

    const shown = page.locator('[data-rich-text]').first();
    await expect(
        shown.locator('strong', { hasText: 'Bold from the toolbar.' }),
    ).toBeVisible();
    await expect(
        shown.locator('li', { hasText: 'First pasted point' }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => (window as unknown as { __pwned?: boolean }).__pwned,
        ),
    ).toBeUndefined();
    await shot(page, '10-rich-text-incident-shown');
});

test('a follow-up is written in rich text', async () => {
    const { page } = chief;
    await page.goto(`${incidentUrl}?tab=timeline`);
    const body = editor(page, 'Update');
    await pasteHtml(
        body,
        '<p><em>Police arrived</em> and <strong>dispersed the crowd</strong>.</p>',
        'Police arrived and dispersed the crowd.',
    );
    await page.getByRole('button', { name: 'Add update' }).click();
    await expect(
        page
            .locator('[data-rich-text] strong', {
                hasText: 'dispersed the crowd',
            })
            .first(),
    ).toBeVisible();
    await shot(page, '11-rich-text-follow-up');
});
