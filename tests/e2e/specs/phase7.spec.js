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
  await expect(page.locator('#runners')).toContainText('Ни один Mac подписи не подключён');
  await page.getByRole('tab', { name: 'Задачи' }).click();
  await expect(page.locator('#jobs-table')).toBeVisible();
});
