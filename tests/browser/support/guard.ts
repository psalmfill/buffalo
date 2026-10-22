import type { Page, Request, Response } from '@playwright/test';

/**
 * Fails a check on any browser console error, failed network request, HTTP
 * error response or Content Security Policy violation. Expected answers
 * (validation 422, step-up 428) are allowed per call with allow().
 */
export class Guard {
    readonly problems: string[] = [];

    private allowed: { status: number; url: RegExp }[] = [];

    constructor(private readonly page: Page) {
        page.on('console', (message) => {
            if (
                message.type() === 'error' &&
                !this.isAllowedConsole(message.text())
            ) {
                this.problems.push(
                    `console error on ${page.url()}: ${message.text()}`,
                );
            }
        });
        page.on('pageerror', (error) =>
            this.problems.push(`page error on ${page.url()}: ${error.message}`),
        );
        page.on('requestfailed', (request: Request) => {
            const failure = request.failure()?.errorText ?? '';
            // A navigation or download that replaces the request is not a failure.
            if (!/ERR_ABORTED|NS_BINDING_ABORTED/.test(failure)) {
                this.problems.push(
                    `request failed: ${request.method()} ${request.url()} (${failure})`,
                );
            }
        });
        page.on('response', (response: Response) => {
            const status = response.status();
            if (
                status >= 400 &&
                !this.allowed.some(
                    (a) => a.status === status && a.url.test(response.url()),
                )
            ) {
                this.problems.push(
                    `HTTP ${status}: ${response.request().method()} ${response.url()}`,
                );
            }
        });
    }

    /** Installs the CSP violation recorder (before any navigation). */
    static async install(page: Page): Promise<Guard> {
        const guard = new Guard(page);
        await page.exposeFunction('__bufCsp', (detail: string) =>
            guard.problems.push(`CSP violation: ${detail}`),
        );
        await page.addInitScript(() => {
            document.addEventListener('securitypolicyviolation', (e) => {
                (
                    window as unknown as { __bufCsp: (d: string) => void }
                ).__bufCsp(
                    `${e.violatedDirective} blocked ${e.blockedURI} on ${location.pathname}`,
                );
            });
        });

        return guard;
    }

    allow(status: number, url: RegExp): void {
        this.allowed.push({ status, url });
    }

    private isAllowedConsole(text: string): boolean {
        // Chrome logs every allowed 4xx response as a console error too.
        return this.allowed.some((a) => text.includes(`status of ${a.status}`));
    }

    assertClean(): void {
        if (this.problems.length > 0) {
            throw new Error(
                `Browser problems:\n- ${this.problems.join('\n- ')}`,
            );
        }
    }
}
