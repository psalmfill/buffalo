import { expect, test } from '@playwright/test';
import { image } from '../support/images';
import { shot, signIn, type Session } from '../support/session';

/** Phase 11D: an organization logo, and a site's photo gallery (Lagos SD). */
test.describe.configure({ mode: 'serial' });

const ORGANIZATION = 'UAT Browser Check Haulage Ltd';
const SITE = 'UAT Browser Check Depot';

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

test('a logo is uploaded and shown in the list and the header', async () => {
    const { page } = sd;
    await page.goto(`/organizations?q=${encodeURIComponent('Browser Check')}`);
    await page.getByRole('link', { name: ORGANIZATION }).click();
    await page.waitForURL(/\/organizations\/[0-9a-z]{26}/);

    await page
        .locator('[data-test="logo-input"]')
        .setInputFiles(await image(page, 'logo.png', '#0f766e', 'image/png'));
    await expect(
        page.getByRole('img', { name: `${ORGANIZATION} logo` }).first(),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: /Replace logo/ }),
    ).toBeVisible();
    await shot(page, '15-logo-header');

    await page.goto(`/organizations?q=${encodeURIComponent('Browser Check')}`);
    await expect(
        page.getByRole('img', { name: `${ORGANIZATION} logo` }).first(),
    ).toBeVisible();
    await shot(page, '16-logo-list');
});

test('site photos are uploaded, a cover is set and the order changed', async () => {
    const { page } = sd;
    await page.goto(`/organizations?q=${encodeURIComponent('Browser Check')}`);
    await page.getByRole('link', { name: ORGANIZATION }).click();
    await page.getByRole('link', { name: SITE }).first().click();
    await page.waitForURL(/\/organization-sites\/[0-9a-z]{26}/);

    const photos = page.locator('[data-test="site-photos"] > li');
    // Reruns: delete earlier test photos (with a reason) to stay well under the 30-photo limit.
    for (let count = await photos.count(); count > 6; count--) {
        await photos.last().getByRole('button', { name: 'Delete photo' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByRole('textbox').fill('Browser check rerun: removing an earlier test photo.');
        await dialog.getByRole('button', { name: 'Delete' }).click();
        await expect(photos).toHaveCount(count - 1);
    }
    const before = await photos.count();
    const files = [
        await image(page, 'gate.jpg', '#1d4ed8'),
        await image(page, 'tank-farm.jpg', '#b45309'),
        await image(page, 'fence.jpg', '#15803d'),
    ];
    await page.locator('[data-test="photo-input"]').setInputFiles(files);
    await expect(photos).toHaveCount(before + 3, { timeout: 60_000 });
    await expect(photos.first().getByText('Cover')).toBeVisible();
    await shot(page, '17-site-photos-uploaded');

    // The newest photo becomes the cover, then the (new) second moves later.
    await photos.last().getByRole('button', { name: 'Set as cover' }).click();
    await expect(photos.first().getByText('Cover')).toBeVisible();
    const order = await photos
        .locator('img')
        .evaluateAll((imgs) => imgs.map((i) => (i as HTMLImageElement).src));
    await photos.nth(1).getByRole('button', { name: 'Move later' }).click();
    await expect
        .poll(async () =>
            photos
                .locator('img')
                .evaluateAll((imgs) =>
                    imgs.map((i) => (i as HTMLImageElement).src),
                ),
        )
        .not.toEqual(order);
    await shot(page, '18-site-photos-cover-reordered');

    await photos
        .first()
        .getByRole('button', { name: /Open photo/ })
        .click();
    await expect(page.getByRole('dialog').locator('img').first()).toBeVisible();
    await shot(page, '19-site-photo-lightbox');
    await page.keyboard.press('Escape');
});

test("the cover shows on the organization's site rows", async () => {
    const { page } = sd;
    await page.goto(`/organizations?q=${encodeURIComponent('Browser Check')}`);
    await page.getByRole('link', { name: ORGANIZATION }).click();
    await expect(
        page.getByRole('img', { name: `${SITE}: cover photo` }),
    ).toBeVisible();
    await shot(page, '20-site-cover-on-organization');
});
