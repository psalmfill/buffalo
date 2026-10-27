import { expect, test } from '@playwright/test';
import { editor } from '../support/editor';
import { downloadRun, shot, signIn, type Session } from '../support/session';

/**
 * Phase 11F: the States section. The DG lists the states and moves between
 * Lagos's page and its dashboard; the Lagos State Director completes and
 * reviews the profile and prints it; a Lagos LGA chief sees the reference
 * tabs only.
 */
test.describe.configure({ mode: 'serial' });

let dg: Session;
let sd: Session;
let chief: Session;

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

test('the side menu shows States & LGAs with States first', async () => {
    const { page } = dg;
    await page.goto('/dashboard?lens=off');
    const group = page
        .locator('[data-sidebar="group"]')
        .filter({ hasText: 'States & LGAs' });
    await expect(group).toBeVisible();
    await expect(group.locator('[data-sidebar="menu-button"]')).toHaveText([
        /States/,
        /LGAs/,
        /Senatorial districts/,
        /Communities/,
    ]);
});

test('the DG sees all 37 states by zone and opens Lagos', async () => {
    const { page } = dg;
    await page.goto('/states?lens=off');
    await expect(page.getByText('37 states')).toBeVisible();
    const lagos = page.locator('tr', {
        has: page.getByRole('link', { name: 'Lagos', exact: true }),
    });
    await expect(lagos).toHaveCount(1);
    // Lagos's figures: its 12 LGAs in the development data.
    await expect(lagos.locator('td').nth(2)).toHaveText('12');
    await shot(page, '40-dg-states-by-zone');

    await lagos.getByRole('link', { name: 'Lagos', exact: true }).click();
    await page.waitForURL(/\/states\/[0-9a-z]{26}$/i);
    await expect(
        page.getByRole('heading', { name: 'Lagos State' }),
    ).toBeVisible();
    for (const tab of [
        'Overview',
        'Profile',
        'LGAs',
        'Senatorial districts',
        'Leadership',
        'Security presence',
        'Incidents',
        'History',
    ]) {
        await expect(
            page
                .getByRole('navigation', { name: 'State sections' })
                .getByRole('link', { name: tab, exact: true }),
        ).toBeVisible();
    }
    await shot(page, '41-dg-lagos-overview');
});

test('the DG moves between the Lagos page and the Lagos state dashboard', async () => {
    const { page } = dg;
    await page.getByRole('link', { name: 'Open state dashboard' }).click();
    await page.waitForURL(/\/dashboard\/states\/[0-9a-z]{26}/i);
    await expect(
        page.getByRole('heading', { name: /Lagos/ }).first(),
    ).toBeVisible();
    await page.locator('[data-test="state-profile-link"]').click();
    await page.waitForURL(/\/states\/[0-9a-z]{26}$/i);
    await expect(
        page.getByRole('heading', { name: 'Lagos State' }),
    ).toBeVisible();

    for (const tab of ['Security presence', 'Incidents', 'LGAs']) {
        await page
            .getByRole('navigation', { name: 'State sections' })
            .getByRole('link', { name: tab, exact: true })
            .click();
        await page.waitForURL(/tab=/);
    }
    await expect(page.locator('table tbody tr').first()).toBeVisible();
    await shot(page, '42-dg-lagos-lgas');
});

test('the Lagos State Director lands on Lagos, completes the profile and marks it reviewed', async () => {
    const { page } = sd;
    await page.goto('/states');
    await page.waitForURL(/\/states\/[0-9a-z]{26}$/i);
    await expect(
        page.getByRole('heading', { name: 'Lagos State' }),
    ).toBeVisible();

    await page
        .getByRole('navigation', { name: 'State sections' })
        .getByRole('link', { name: 'Profile', exact: true })
        .click();
    await page.waitForURL(/tab=profile/);
    const stamp = new Date().toISOString().slice(0, 16);
    const fill = async (label: string, text: string) => {
        const box = editor(page, label);
        await box.click();
        await page.keyboard.press('ControlOrMeta+A');
        await page.keyboard.type(text);
    };
    await fill('History', `Created in 1967 (browser check ${stamp}).`);
    await fill('Economic activities', 'Ports, trade, finance and fishing.');
    await fill(
        'Major roads',
        'Lagos–Ibadan Expressway; Lagos–Badagry Expressway.',
    );
    await fill(
        'Borders (neighbouring states, international borders, border crossings)',
        'Ogun State to the north and east; the Republic of Benin to the west, crossing at Seme.',
    );
    const urban = page.getByRole('checkbox', { name: 'Urban' });
    if (!(await urban.isChecked())) {
        await urban.click();
    }
    await page.getByRole('button', { name: 'Save profile' }).click();
    await expect(page.getByText('Profile saved.')).toBeVisible();

    await page
        .getByRole('navigation', { name: 'State sections' })
        .getByRole('link', { name: 'Overview', exact: true })
        .click();
    await page.getByRole('button', { name: 'Mark as reviewed' }).click();
    await expect(page.getByText('Profile marked as reviewed.')).toBeVisible();
    await expect(page.getByText(/ by .+/).first()).toBeVisible();
    await shot(page, '43-sd-lagos-reviewed');
});

test('the state profile PDF downloads', async () => {
    const { page } = sd;
    await page.locator('[data-test="export-report"]').click();
    await page.waitForURL(/\/reports\?type=state_profile/);
    await page.getByRole('button', { name: 'Generate' }).click();
    const pdf = await downloadRun(page);
    expect(pdf.subarray(0, 5).toString()).toBe('%PDF-');
    await shot(page, '44-sd-state-profile-pdf');
});

test('a Lagos LGA chief sees the reference tabs only, with their own LGA', async () => {
    const { page } = chief;
    await page.goto('/states');
    await page.waitForURL(/\/states\/[0-9a-z]{26}$/i);
    const tabs = page
        .getByRole('navigation', { name: 'State sections' })
        .getByRole('link');
    await expect(tabs).toHaveText([
        'Overview',
        'Profile',
        'LGAs',
        'Senatorial districts',
        'Leadership',
    ]);
    await expect(
        page.getByRole('link', { name: 'Open state dashboard' }),
    ).toHaveCount(0);
    await shot(page, '45-chief-lagos-overview');

    await tabs.filter({ hasText: 'Profile' }).click();
    await page.waitForURL(/tab=profile/);
    await expect(page.getByText(/Republic of Benin/)).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Save profile' }),
    ).toHaveCount(0);

    await tabs.filter({ hasText: /^LGAs$/ }).click();
    await page.waitForURL(/tab=lgas/);
    await expect(page.locator('table tbody tr')).toHaveCount(1);
    await shot(page, '46-chief-lagos-own-lga');

    for (const tab of ['Senatorial districts', 'Leadership']) {
        await tabs.filter({ hasText: tab }).click();
        await page.waitForURL(/tab=/);
    }
});
