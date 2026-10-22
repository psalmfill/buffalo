import type { Locator, Page } from '@playwright/test';

/** The Tiptap editor labelled `label` (its contenteditable carries the label). */
export function editor(page: Page, label: string): Locator {
    return page.locator(`.ProseMirror[aria-label="${label}"]`);
}

/**
 * Pastes formatted HTML into an editor as a browser would from Word or a
 * web page (a paste event with text/html on the clipboard).
 */
export async function pasteHtml(
    target: Locator,
    html: string,
    text: string,
): Promise<void> {
    await target.click();
    await target.evaluate(
        (el, data) => {
            const transfer = new DataTransfer();
            transfer.setData('text/html', data.html);
            transfer.setData('text/plain', data.text);
            el.dispatchEvent(
                new ClipboardEvent('paste', {
                    clipboardData: transfer,
                    bubbles: true,
                    cancelable: true,
                }),
            );
        },
        { html, text },
    );
}

/** Word-like pasted content: styles, classes, a span colour, a script and an image. */
export const WORD_PASTE =
    '<p class="MsoNormal" style="margin:0"><b>Pasted bold</b> and <span style="color:red;font-family:Calibri">red Calibri</span> text<o:p></o:p></p>' +
    '<ul><li>First pasted point</li><li>Second pasted point</li></ul>' +
    '<script>window.__pwned = true</script><img src="https://example.invalid/x.png" onerror="window.__pwned = true">';
