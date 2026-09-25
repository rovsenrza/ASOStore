#!/usr/bin/env node
/**
 * Smoke test for the published portal and admin panel (IMPLEMENTATION_PLAN P1-OPS-02).
 *
 *   1. Every page answers 200.
 *   2. Every local href/src and every ES-module import they reach answers 200.
 *   3. With CHROME_BIN set, key pages render API data in a headless browser.
 *
 * Usage: BASE_URL=http://127.0.0.1:8000 [CHROME_BIN=/path/to/chrome] node scripts/web-smoke.mjs
 * Requires scripts/build-public.sh and a running backend.
 */
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const PAGES = [
  '/', '/activate.html', '/install.html', '/account.html', '/login.html', '/register.html',
  '/support.html', '/pricing.html', '/terms.html', '/privacy.html', '/forgot-password.html', '/reset-password.html',
  '/admin/', '/admin/login.html', '/admin/users.html', '/admin/devices.html', '/admin/apps.html',
  '/admin/artifacts.html', '/admin/teams.html', '/admin/jobs.html', '/admin/audit.html',
];

const failures = [];
const checked = new Set();

function localReferences(body, url, contentType) {
  const refs = [];
  if (contentType.includes('html')) {
    for (const [, ref] of body.matchAll(/\s(?:href|src)="([^"#]+)(?:#[^"]*)?"/g)) refs.push(ref);
  }
  if (contentType.includes('javascript')) {
    for (const [, ref] of body.matchAll(/(?:import|from)\s*['"]([^'"]+)['"]/g)) refs.push(ref);
  }
  return refs
    .filter((ref) => !/^(https?:|data:|mailto:|tel:)/.test(ref))
    .map((ref) => new URL(ref, url).href);
}

async function check(url, from) {
  if (checked.has(url)) return;
  checked.add(url);

  const response = await fetch(url);
  if (response.status !== 200) {
    failures.push(`${response.status} ${url}${from ? ` (linked from ${from})` : ''}`);
    return;
  }

  const contentType = response.headers.get('content-type') ?? '';
  if (contentType.includes('html') || contentType.includes('javascript')) {
    for (const ref of localReferences(await response.text(), url, contentType)) await check(ref, url);
  }
}

function renderedText(path) {
  const dom = execFileSync(process.env.CHROME_BIN, [
    '--headless', '--disable-gpu', '--no-sandbox', '--virtual-time-budget=6000', '--dump-dom', BASE + path,
  ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  return dom.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
}

for (const page of PAGES) await check(BASE + page);

if (process.env.CHROME_BIN) {
  // Signed-out views; the admin panel sends visitors to its sign-in page.
  const expectations = [
    ['/activate.html', 'Вы не вошли в аккаунт'],
    ['/account.html', 'Вы не вошли в аккаунт'],
    ['/admin/', 'Продолжить'],
    ['/admin/users.html', 'Продолжить'],
  ];
  for (const [path, text] of expectations) {
    if (!renderedText(path).includes(text)) failures.push(`${path} did not render "${text}"`);
  }
}

if (failures.length) {
  console.error(`Web smoke failed:\n  ${failures.join('\n  ')}`);
  process.exit(1);
}
console.log(`Web smoke passed: ${checked.size} URLs${process.env.CHROME_BIN ? ' + rendered pages' : ''}.`);
