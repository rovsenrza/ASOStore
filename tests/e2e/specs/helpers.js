import { createHmac } from 'node:crypto';

/**
 * RFC 6238 code for a base32 secret, as an authenticator app would show it.
 */
export function totp(secret, at = Date.now()) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret.replace(/\s+/g, '').toUpperCase()) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2)));

  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
  const digest = createHmac('sha1', key).update(counter).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

export const ADMIN = { email: 'admin@storefront.test', password: process.env.ADMIN_PASSWORD ?? 'e2e-admin-password-1' };
export const CUSTOMER = { name: 'Анна Смирнова', email: `anna.${Date.now()}@example.com`, password: 'e2e customer 2026' };
