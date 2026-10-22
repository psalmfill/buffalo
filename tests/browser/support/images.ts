import type { Page } from '@playwright/test';

/** A real image drawn on a canvas in the page (no image files in the repo). */
export async function image(
    page: Page,
    name: string,
    colour: string,
    type: 'image/png' | 'image/jpeg' = 'image/jpeg',
): Promise<{ name: string; mimeType: string; buffer: Buffer }> {
    const data = await page.evaluate(
        ({ colour, label, type }) => {
            const canvas = document.createElement('canvas');
            canvas.width = 640;
            canvas.height = 420;
            const ctx = canvas.getContext('2d') as CanvasRenderingContext2D;
            ctx.fillStyle = colour;
            ctx.fillRect(0, 0, 640, 420);
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 36px sans-serif';
            ctx.fillText(label, 40, 220);

            return canvas.toDataURL(type, 0.9).split(',')[1];
        },
        { colour, label: name, type },
    );

    return { name, mimeType: type, buffer: Buffer.from(data, 'base64') };
}
