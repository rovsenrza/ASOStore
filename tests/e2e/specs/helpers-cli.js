import { execFileSync } from 'node:child_process';
import path from 'node:path';

/*
 * Server-side helpers for browser tests. scripts/e2e.sh exports the e2e
 * database settings, so these commands act on the same data as the server.
 */
const ROOT = path.resolve(import.meta.dirname, '../../..');

const ADMIN_EMAIL = 'admin@storefront.test';

export function artisan(...args) {
  return execFileSync('php', ['artisan', ...args], { cwd: path.join(ROOT, 'backend'), encoding: 'utf8' });
}

export function issueActivationCode() {
  return artisan('activation:issue', '--count=1', '--days=30', '--note=E2E').trim().split('\n').pop().trim();
}

/** What an iPhone posts back to the enrollment URL (see scripts/device-payload.php). */
export function devicePayload(challenge, udid) {
  return execFileSync('php', [path.join(ROOT, 'scripts/device-payload.php'), challenge, udid]);
}

/**
 * Staff sign-in starts from a fresh TOTP enrollment. Clearing the cache also
 * resets the 5-per-minute admin-login limiter, which consecutive specs hit.
 */
export function resetAdminSignIn(reason) {
  artisan('cache:clear');
  artisan('admin:reset-totp', ADMIN_EMAIL, `--reason=${reason}`);
}
