import { expect, test } from '@playwright/test';
import { artisan, resetAdminSignIn, devicePayload, issueActivationCode } from './helpers-cli.js';
import { ADMIN, totp } from './helpers.js';

/*
 * Phase 3 journey: an iPhone is enrolled through the Profile Service flow and
 * registered with Apple (fake driver), and an admin inspects it.
 */
test.describe.configure({ mode: 'serial' });

const UDID = '00008030-001A2B3C4D5E6F70';
const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1';
const customer = { name: 'Иван Петров', email: `ivan.${Date.now()}@example.com`, password: 'e2e iphone 2026' };

test.describe('on an iPhone in Safari', () => {
  test.use({ userAgent: IPHONE_SAFARI, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });

  test('customer enrols the iPhone and reaches the install step', async ({ page, baseURL }) => {
    await page.goto('/register.html');
    await page.getByLabel('Имя').fill(customer.name);
    await page.getByLabel('Эл. почта').fill(customer.email);
    await page.getByLabel('Пароль').fill(customer.password);
    await page.getByRole('button', { name: 'Создать аккаунт' }).click();
    await expect(page).toHaveURL(/\/activate\.html$/);

    await page.getByLabel('Код активации').fill(issueActivationCode());
    await page.getByRole('button', { name: 'Активировать' }).click();

    await expect(page.locator('#stage-state')).toContainText('Зарегистрируйте iPhone');
    await expect(page.locator('#check-device')).toHaveText('iPhone');
    await expect(page.locator('#check-browser')).toHaveText('Safari');
    const install = page.getByRole('link', { name: 'Установить профиль' });
    await expect(install).toHaveAttribute('href', '/api/v1/devices/enrollment-profile');

    // What Safari downloads, and what iOS then posts back.
    const profile = await page.request.get('/api/v1/devices/enrollment-profile', { headers: { Referer: `${baseURL}/` } });
    expect(profile.headers()['content-type']).toBe('application/x-apple-aspen-config');
    const challenge = (await profile.text()).match(/<key>Challenge<\/key>\s*<string>([^<]+)<\/string>/)[1];

    const answer = await page.request.post(`/api/v1/devices/enrollment/callback?challenge=${encodeURIComponent(challenge)}`, {
      data: devicePayload(challenge, UDID),
      headers: { 'Content-Type': 'application/pkcs7-signature' },
      maxRedirects: 0,
    });
    expect(answer.status()).toBe(301);

    await page.goto(answer.headers().location);
    await expect(page.locator('#stage-state')).toContainText('Устройство получено');
    await expect(page.locator('#stage-state')).toContainText('Ru App Store готов к установке');
    await expect(page.locator('#stage-state')).toContainText('••••-6F70');
    await expect(page.locator('#step-title')).toHaveText('Установка');

    await page.goto('/account.html');
    await expect(page.locator('#account-state')).toContainText('Зарегистрировано');
    await expect(page.locator('#account-state')).not.toContainText(UDID);
  });

  test('a used enrollment profile is refused with a readable message', async ({ page }) => {
    await page.goto('/activate.html?enrollment_error=ENROLLMENT_CHALLENGE_EXPIRED');
    await expect(page.getByRole('alert')).toContainText('Профиль устарел или уже использован');
  });

  test('the install page opens the native app with a one-time link', async ({ page }) => {
    await page.goto('/login.html');
    await page.getByLabel('Эл. почта').fill(customer.email);
    await page.getByLabel('Пароль').fill(customer.password);
    await page.getByRole('button', { name: 'Войти' }).click();
    await expect(page).toHaveURL(/\/account\.html$/);

    await page.goto('/install.html');
    const open = page.getByRole('button', { name: 'Уже установили Ru App Store? Открыть приложение' });
    await expect(open).toBeVisible();

    const claim = page.waitForResponse((response) => response.url().endsWith('/api/v1/storefront/claims'));
    await open.click();
    expect((await claim).status()).toBe(201);
    expect((await (await claim).json()).data.url).toMatch(/^storefront:\/\/claim\?code=/);
  });
});

test('admin finds the device and reveals its UDID with a reason', async ({ page }) => {
  resetAdminSignIn('E2E phase 3');

  await page.goto('/admin/login.html');
  await page.getByLabel('Эл. почта').fill(ADMIN.email);
  await page.getByLabel('Пароль').fill(ADMIN.password);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  const secret = (await page.locator('.secret code').textContent()).replace(/\s+/g, '');
  await page.getByLabel('Код из приложения-аутентификатора').fill(totp(secret));
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/admin\/index\.html$/);

  await page.goto('/admin/devices.html');
  await page.getByRole('searchbox', { name: 'Последние 4 символа UDID или эл. почта' }).fill('6F70');
  const row = page.locator('#devices-table tbody tr').filter({ hasText: customer.email });
  await expect(row).toContainText('Зарегистрировано');
  await expect(row).not.toContainText(UDID);
  await row.getByRole('button', { name: 'Открыть' }).click();

  const dialog = page.locator('.panel-dialog');
  await expect(dialog).toContainText('FAKE000001');
  await dialog.getByRole('button', { name: 'Показать UDID' }).click();

  const confirm = page.locator('.confirm-dialog');
  await confirm.getByLabel('Причина (попадёт в журнал аудита)').fill('Проверка регистрации');
  await confirm.getByRole('button', { name: 'Подтвердить' }).click();
  await expect(dialog.locator('.revealed-udid')).toHaveText(UDID);

  await page.goto('/admin/audit.html');
  await page.getByPlaceholder('Действие (например, user.)').fill('device.udid_revealed');
  await expect(page.locator('#audit-table tbody')).toContainText('device.udid_revealed');
});
