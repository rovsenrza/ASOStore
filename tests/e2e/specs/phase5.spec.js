import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import { ADMIN, totp } from './helpers.js';
import { makeIpa, resetAdminSignIn } from './helpers-cli.js';

/*
 * Phase 5 journey (P5-ADM-01): a batch of IPAs is uploaded from the admin
 * panel; each file gets its own result, and a valid one is reviewed and published.
 */
test.describe.configure({ mode: 'serial' });

const dir = mkdtempSync(path.join(tmpdir(), 'ipa-'));
const valid = path.join(dir, 'Demo.ipa');
const broken = path.join(dir, 'Broken.ipa');
const version = `1.${Date.now() % 100000}.0`;

async function signInAdmin(page) {
  resetAdminSignIn('E2E phase 5');
  await page.goto('/admin/login.html');
  await page.getByLabel('Эл. почта').fill(ADMIN.email);
  await page.getByLabel('Пароль').fill(ADMIN.password);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  const secret = (await page.locator('.secret code').textContent()).replace(/\s+/g, '');
  await page.getByLabel('Код из приложения-аутентификатора').fill(totp(secret));
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/admin\/index\.html$/);
}

test('uploads a batch where one file fails independently, then reviews and publishes the good one', async ({ page }) => {
  makeIpa(valid, 'com.example.e2e', version, '7');
  writeFileSync(broken, 'this is not an ipa');

  await signInAdmin(page);

  // The team must be allowed to distribute the bundle ID, or compatibility rejects it.
  await page.goto('/admin/teams.html');
  await page.getByRole('tab', { name: 'Разрешения приложений' }).click();
  await page.getByLabel('Bundle ID').fill('com.example.e2e');
  await page.getByLabel('Основание').fill('Собственная сборка');
  await page.getByRole('button', { name: 'Разрешить' }).click();
  await expect(page.locator('#eligibilities')).toContainText('com.example.e2e → FAKE000001');

  await page.goto('/admin/artifacts.html');
  await page.getByLabel('Приложение в каталоге').selectOption({ index: 1 });
  await page.getByLabel('Файлы IPA').setInputFiles([valid, broken]);

  // The declaration is mandatory.
  await page.getByRole('button', { name: 'Загрузить' }).click();
  await expect(page.locator('#upload-form .form-status')).toContainText('Подтвердите ответственность');

  await page.getByLabel('Файлы IPA').setInputFiles([valid, broken]);
  await page.locator('#upload-form [name="declaration_accepted"]').check();
  await page.getByRole('button', { name: 'Загрузить' }).click();

  const good = page.locator('.upload-row').filter({ hasText: 'Demo.ipa' });
  const bad = page.locator('.upload-row').filter({ hasText: 'Broken.ipa' });
  await expect(good).toContainText('Ждёт проверки происхождения', { timeout: 30000 });
  await expect(bad).toContainText('Проверка не пройдена', { timeout: 30000 });
  await expect(bad).toContainText('INVALID_ARCHIVE');

  // Review the good one from the queue.
  await good.getByRole('button', { name: 'Открыть' }).click();
  const dialog = page.locator('.panel-dialog').last();
  await expect(dialog).toContainText('com.example.e2e');
  await dialog.getByRole('button', { name: 'Подтвердить происхождение' }).click();

  const approve = page.locator('.panel-dialog').last();
  await approve.getByLabel('Источник файла проверен и совпадает с заявленным').check();
  await approve.getByLabel('Права на распространение подтверждены').check();
  await approve.getByLabel('Отчёт технической проверки просмотрен').check();
  await approve.getByLabel(/Антивирус не подтвердил/).check();
  await approve.getByRole('button', { name: 'Подтвердить' }).click();

  await expect(dialog).toContainText('Готов к публикации');
  await dialog.getByRole('button', { name: 'Опубликовать' }).click();
  await page.locator('.confirm-dialog').getByRole('button', { name: 'Подтвердить' }).click();
  await expect(dialog).toContainText('Опубликован');
});

test('uploads a file larger than one chunk', async ({ page }) => {
  const big = path.join(dir, 'Big.ipa');
  makeIpa(big, 'com.example.e2e', `2.${Date.now() % 100000}.0`, '8', 17);

  await signInAdmin(page);
  await page.goto('/admin/artifacts.html');
  await page.getByLabel('Приложение в каталоге').selectOption({ index: 1 });
  await page.getByLabel('Файлы IPA').setInputFiles(big);
  await page.locator('#upload-form [name="declaration_accepted"]').check();
  await page.getByRole('button', { name: 'Загрузить' }).click();

  const row = page.locator('.upload-row').filter({ hasText: 'Big.ipa' });
  await expect(row).toContainText('Ждёт проверки происхождения', { timeout: 60000 });
});

test('pauses and resumes an upload without sending chunks twice', async ({ page }) => {
  const big = path.join(dir, 'Resume.ipa');
  makeIpa(big, 'com.example.e2e', `3.${Date.now() % 100000}.0`, '9', 17);
  const sent = [];

  await signInAdmin(page);
  // Slow down chunk 1 so there is time to pause.
  await page.route('**/api/v1/admin/uploads/*/chunks/*', async (route) => {
    const chunk = route.request().url().split('/').pop();
    sent.push(chunk);
    if (chunk === '1' && sent.filter((n) => n === '1').length === 1) await new Promise((resolve) => { setTimeout(resolve, 3000); });
    try { await route.continue(); } catch { /* aborted by the pause */ }
  });

  await page.goto('/admin/artifacts.html');
  await page.getByLabel('Приложение в каталоге').selectOption({ index: 1 });
  await page.getByLabel('Файлы IPA').setInputFiles(big);
  await page.locator('#upload-form [name="declaration_accepted"]').check();
  await page.getByRole('button', { name: 'Загрузить' }).click();

  const row = page.locator('.upload-row').filter({ hasText: 'Resume.ipa' });
  await row.getByRole('button', { name: 'Пауза' }).click();
  await expect(row).toContainText('Приостановлено');

  await row.getByRole('button', { name: 'Продолжить' }).click();
  await expect(row).toContainText('Ждёт проверки происхождения', { timeout: 60000 });
  // Chunk 0 went once; only the interrupted chunk was sent again.
  expect(sent.filter((n) => n === '0')).toHaveLength(1);
  expect(sent.filter((n) => n === '2')).toHaveLength(1);
});
