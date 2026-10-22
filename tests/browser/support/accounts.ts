import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { totp } from './totp';

export type Account = { email: string; password: string; secret: string };

/**
 * UAT test accounts, read from the credentials table that `make uat-up` or
 * `make uat-credentials` prints (BUFFALO_CREDENTIALS; default .uat/credentials.txt,
 * git-ignored). Test values for the UAT environment only; never printed.
 */
let cache: Record<string, Account> | null = null;

export function account(local: string): Account {
    if (cache === null) {
        const file =
            process.env.BUFFALO_CREDENTIALS ??
            resolve(process.cwd(), '../../.uat/credentials.txt');
        cache = {};
        for (const line of readFileSync(file, 'utf8').split('\n')) {
            const cells = line
                .trim()
                .replace(/^\||\|$/g, '')
                .split('|')
                .map((c) => c.trim());
            if (cells.length >= 4 && cells[0].includes('@')) {
                cache[cells[0].split('@')[0]] = {
                    email: cells[0],
                    password: cells.slice(2, -1).join('|'),
                    secret: cells[cells.length - 1],
                };
            }
        }
    }
    const found = cache[local];
    if (!found) {
        throw new Error(
            `No UAT credentials for ${local}: set BUFFALO_CREDENTIALS to the table printed by make uat-credentials.`,
        );
    }

    return found;
}

/** The last TOTP window used per account: the server refuses a code twice. */
const used = new Map<string, number>();

/**
 * A code not used before by this run (waits for the next window if needed).
 * `nextWindow`: the server refused this window's code (used by an earlier
 * run: replay protection), so wait for the next one.
 */
export async function freshCode(
    who: Account,
    nextWindow = false,
): Promise<string> {
    let window = Math.floor(Date.now() / 30_000);
    if (nextWindow || used.get(who.email) === window) {
        await new Promise((r) =>
            setTimeout(r, (window + 1) * 30_000 - Date.now() + 1_000),
        );
        window = Math.floor(Date.now() / 30_000);
    }
    used.set(who.email, window);

    return totp(who.secret);
}
