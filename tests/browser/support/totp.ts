import { createHmac } from 'node:crypto';

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s), as authenticator apps compute it. */
export function totp(secret: string, at = Date.now()): string {
    const key = base32(secret);
    const counter = Math.floor(at / 1000 / 30);
    const message = Buffer.alloc(8);
    message.writeBigUInt64BE(BigInt(counter));
    const digest = createHmac('sha1', key).update(message).digest();
    const offset = digest[digest.length - 1] & 0x0f;
    const code = (digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return code.toString().padStart(6, '0');
}

function base32(input: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const clean = input.replace(/=+$/, '').replace(/\s+/g, '').toUpperCase();
    let bits = '';
    for (const char of clean) {
        const value = alphabet.indexOf(char);
        if (value < 0) {
            throw new Error('Invalid base32 TOTP secret.');
        }
        bits += value.toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }

    return Buffer.from(bytes);
}
