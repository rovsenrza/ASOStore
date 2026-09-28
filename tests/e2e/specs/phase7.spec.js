import { expect, test } from '@playwright/test';
import { ADMIN, totp } from './helpers.js';
import { resetAdminSignIn } from './helpers-cli.js';

/*
 * Phases 6–7 operator screens: Apple teams with quotas and app eligibility,
 * and the signing runner / jobs view.
 */
test.describe.configure({ mode: 'serial' });

async function signInAdmin(page) {
  resetAdminSignIn('E2E phase 7');
  await page.goto('/admin/login.html');
  await page.getByLabel('Эл. почта').fill(ADMIN.email);
  await page.getByLabel('Пароль').fill(ADMIN.password);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  const secret = (await page.locator('.secret code').textContent()).replace(/\s+/g, '');
  await page.getByLabel('Код из приложения-аутентификатора').fill(totp(secret));
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/admin\/index\.html$/);
}

test('admin reviews Apple teams, approves an app for a team and sees runner health', async ({ page }) => {
  await signInAdmin(page);
  await expect(page.locator('#widget-quota')).toBeVisible();

  await page.goto('/admin/teams.html');
  const card = page.locator('.team-card').filter({ hasText: 'FAKE000001' });
  await expect(card).toContainText('Активна');
  await expect(card).toContainText('Год членства');

  await page.getByRole('tab', { name: 'Разрешения приложений' }).click();
  await page.getByLabel('Bundle ID').fill('com.example.e2e');
  await page.getByLabel('Основание').fill('Собственная сборка');
  await page.getByRole('button', { name: 'Разрешить' }).click();
  await expect(page.locator('#eligibilities')).toContainText('com.example.e2e → FAKE000001');

  await page.getByRole('tab', { name: 'Назначения' }).click();
  await expect(page.locator('#approvals')).toContainText('Нет назначений');

  await page.goto('/admin/jobs.html');
  await expect(page.locator('#runners')).toContainText('Ни один сервер подписи не подключён');
  await page.getByRole('tab', { name: 'Задачи' }).click();
  await expect(page.locator('#jobs-table')).toBeVisible();
});

// The production CSP (App\Http\Middleware\SecurityHeaders::CSP and public/.htaccess). The test
// server does not send it for static files, so it is added here to catch violations.
const CSP = "default-src 'self'; img-src 'self' data: blob:; style-src 'self' https://fonts.googleapis.com; "
  + "font-src 'self' https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; object-src 'none'; "
  + "frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

test('every page works under the enforced Content Security Policy', async ({ page }) => {
  const violations = [];
  page.on('console', (message) => {
    if (/Content Security Policy|Refused to/i.test(message.text())) violations.push(`${page.url()}: ${message.text()}`);
  });
  await page.route('**/*', async (route) => {
    const response = await route.fetch();
    const headers = { ...response.headers() };
    if ((headers['content-type'] ?? '').includes('text/html')) headers['content-security-policy'] = CSP;
    await route.fulfill({ response, headers });
  });

  for (const path of ['/', '/install.html', '/activate.html', '/account.html', '/support.html', '/privacy.html', '/admin/login.html']) {
    await page.goto(path);
    await expect(page.locator('main')).toBeVisible();
  }
  expect(violations).toEqual([]);
});

test('a signed-out visitor can reach support', async ({ page }) => {
  await page.goto('/support.html');
  await page.getByLabel('Эл. почта для ответа').fill('visitor@example.com');
  await page.getByLabel('Тема').selectOption('INSTALL');
  await page.getByLabel('Что случилось').fill('Storefront перестал открываться после обновления iOS.');
  await page.getByRole('button', { name: 'Отправить' }).click();
  await expect(page.getByText('Обращение отправлено')).toBeVisible();
});
