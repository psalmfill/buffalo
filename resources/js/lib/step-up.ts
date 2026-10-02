/**
 * Step-up authentication in the browser (Phase 8B.2).
 *
 * When the server answers 428 with `{ step_up: true }`, callers await
 * requestStepUp(): the StepUpDialog asks for a TOTP code, confirms it with
 * the server, and resolves; the caller then retries its request. Several
 * requests needing step-up at once share one dialog.
 */
export class StepUpCancelled extends Error {
    constructor() {
        super('Confirmation cancelled.');
    }
}

type Handler = (done: {
    resolve: () => void;
    reject: (error: Error) => void;
}) => void;

let handler: Handler | null = null;
let pending: Promise<void> | null = null;

/** Registers the dialog that answers step-up requests (one at a time). */
export function registerStepUpHandler(next: Handler): () => void {
    handler = next;

    return () => {
        if (handler === next) {
            handler = null;
        }
    };
}

export function requestStepUp(): Promise<void> {
    if (pending) {
        return pending;
    }

    pending = new Promise<void>((resolve, reject) => {
        if (!handler) {
            reject(new StepUpCancelled());

            return;
        }

        handler({ resolve, reject });
    }).finally(() => {
        pending = null;
    });

    return pending;
}

/** Whether a response body is the server's step-up request. */
export function isStepUpResponse(status: number, body: unknown): boolean {
    return (
        status === 428 &&
        typeof body === 'object' &&
        body !== null &&
        (body as { step_up?: unknown }).step_up === true
    );
}
