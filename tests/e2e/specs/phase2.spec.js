import { expect, test } from '@playwright/test';
import { ADMIN, CUSTOMER, totp } from './helpers.js';

/*
 * Phase 2 journey (IMPLEMENTATION_PLAN Phase 2 gate), through real cookies,
 * CSRF tokens and TOTP — the parts unit tests cannot cover.
 */
// signInAdmin may wait up to 31 s for a fresh TOTP window, longer than the default timeout.
test.describe.configure({ mode: 'serial', timeout: 90_000 });

let adminSecret;
let activationCode;

test('admin panel sends signed-out operators to the sign-in page', async ({ page }) => {
  await page.goto('/admin/users.html');
  await expect(page).toHaveURL(/\/admin\/login\.html\?next=%2Fadmin%2Fusers\.html/);
});

test('admin enrols an authenticator and signs in', async ({ page }) => {
  await page.goto('/admin/login.html');
  await page.getByLabel('Эл. почта').fill(ADMIN.email);
  await page.getByLabel('Пароль').fill('wrong password');
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await expect(page.getByRole('alert')).toContainText('Неверная эл. почта или пароль');

  await page.getByLabel('Пароль').fill(ADMIN.password);
  await page.getByRole('button', { name: 'Продолжить' }).click();

  await expect(page.getByRole('heading', { name: 'Подключите приложение-аутентификатор' })).toBeVisible();
  await expect(page.locator('img.qr')).toBeVisible();
  adminSecret = (await page.locator('.secret code').textContent()).replace(/\s+/g, '');

  await page.getByLabel('Код из приложения-аутентификатора').fill(totp(adminSecret));
  await page.getByRole('button', { name: 'Войти' }).click();

  await expect(page).toHaveURL(/\/admin\/index\.html$/);
  await expect(page.locator('.sidebar-foot')).toContainText(ADMIN.email);
  await expect(page.locator('#widget-health')).toContainText('Работает');
});

test('admin issues an activation code that is shown once', async ({ page }) => {
  await signInAdmin(page);
  await page.goto('/admin/users.html');
  await page.getByRole('tab', { name: 'Коды активации' }).click();

  const form = page.locator('#generate-form');
  await form.getByLabel('Количество').fill('1');
  await form.getByLabel('Заметка').fill('E2E');
  await form.getByRole('button', { name: 'Выпустить коды' }).click();

  const result = page.locator('.batch-result');
  await expect(result).toContainText('Коды показываются только сейчас');
  activationCode = (await result.locator('.code-list li').first().textContent()).trim();
  expect(activationCode).toMatch(/^[0-9A-Z]{4}(-[0-9A-Z]{4}){3}$/);

  await expect(page.locator('#codes-table')).toContainText(`••••-${activationCode.slice(-4)}`);
});

test('customer registers, redeems the code and reaches device setup', async ({ page }) => {
  await page.goto('/register.html');
  await page.getByLabel('Имя').fill(CUSTOMER.name);
  await page.getByLabel('Эл. почта').fill(CUSTOMER.email);
  await page.getByLabel('Пароль').fill('short');
  await page.getByRole('button', { name: 'Создать аккаунт' }).click();
  // Native validation stops the short password before it reaches the API.
  await expect(page).toHaveURL(/register\.html/);

  await page.getByLabel('Пароль').fill(CUSTOMER.password);
  await page.getByRole('button', { name: 'Создать аккаунт' }).click();

  await expect(page).toHaveURL(/\/activate\.html$/);
  await expect(page.locator('#stage-state')).toContainText('Нужен код активации');

  const code = page.getByLabel('Код активации');
  await code.fill('AAAA-BBBB-CCCC-DDDD');
  await page.getByRole('button', { name: 'Активировать' }).click();
  await expect(page.getByRole('alert')).toContainText('Код активации недействителен');

  await code.fill(activationCode.toLowerCase().replaceAll('-', ' '));
  await page.getByRole('button', { name: 'Активировать' }).click();
  await expect(page.locator('#stage-state')).toContainText('Зарегистрируйте iPhone');
  await expect(page.locator('#step-title')).toHaveText('Профиль');

  await page.goto('/account.html');
  await expect(page.locator('#profile')).toContainText(CUSTOMER.email);
  await expect(page.locator('#profile')).toContainText('standard');

  await page.getByRole('button', { name: 'Выйти' }).click();
  await expect(page).toHaveURL(/\/$/);
  await page.goto('/account.html');
  await expect(page.locator('#profile')).toContainText('Вы не вошли в аккаунт');
});

test('customer signs back in and is returned to the page they came from', async ({ page }) => {
  await page.goto('/login.html?next=/activate.html');
  await page.getByLabel('Эл. почта').fill(CUSTOMER.email);
  await page.getByLabel('Пароль').fill('wrong password 1');
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page.getByRole('alert')).toContainText('Неверная эл. почта или пароль');

  await page.getByLabel('Пароль').fill(CUSTOMER.password);
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/activate\.html$/);
});

test('password reset request answers without revealing accounts', async ({ page }) => {
  await page.goto('/forgot-password.html');
  await page.getByLabel('Эл. почта').fill('nobody@example.com');
  await page.getByRole('button', { name: 'Отправить ссылку' }).click();
  await expect(page.getByRole('status')).toContainText('Если аккаунт с этим адресом существует');
});

test('admin finds the customer and the audit trail', async ({ page }) => {
  await signInAdmin(page);
  await page.goto('/admin/users.html');
  await page.getByRole('searchbox', { name: 'Имя или эл. почта' }).fill(CUSTOMER.email);
  const row = page.locator('#users-table tbody tr').filter({ hasText: CUSTOMER.email });
  await expect(row).toHaveCount(1);
  await row.getByRole('button', { name: 'Открыть' }).click();

  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText(CUSTOMER.name);
  await expect(dialog).toContainText('activation_code.redeemed');
  await dialog.getByRole('button', { name: 'Закрыть' }).click();

  await page.goto('/admin/audit.html');
  await page.getByPlaceholder('Действие (например, user.)').fill('activation_code.');
  await expect(page.locator('#audit-table tbody')).toContainText('activation_code.redeemed');
  await expect(page.locator('#audit-table tbody')).toContainText('activation_code.batch_created');
});

/**
 * Enrolled admin sign-in. Waits for a fresh TOTP window so a code is never reused.
 */
async function signInAdmin(page) {
  await page.goto('/admin/login.html');
  await page.getByLabel('Эл. почта').fill(ADMIN.email);
  await page.getByLabel('Пароль').fill(ADMIN.password);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await expect(page.getByLabel('Код из приложения-аутентификатора')).toBeVisible();

  const secondsIntoWindow = Math.floor(Date.now() / 1000) % 30;
  await page.waitForTimeout((30 - secondsIntoWindow + 1) * 1000);
  await page.getByLabel('Код из приложения-аутентификатора').fill(totp(adminSecret));
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/admin\/index\.html$/);
}
