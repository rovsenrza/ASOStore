import { expect, test } from '@playwright/test';
import { ADMIN, totp } from './helpers.js';
import { resetAdminSignIn } from './helpers-cli.js';

/*
 * Phase 4 journey: a catalog manager creates a listing, publishes it, and it
 * appears in the customer-facing catalog API.
 */
test.describe.configure({ mode: 'serial' });

const appName = `Заметки ${Date.now()}`;

async function signInAdmin(page) {
  resetAdminSignIn('E2E phase 4');
  await page.goto('/admin/login.html');
  await page.getByLabel('Эл. почта').fill(ADMIN.email);
  await page.getByLabel('Пароль').fill(ADMIN.password);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  const secret = (await page.locator('.secret code').textContent()).replace(/\s+/g, '');
  await page.getByLabel('Код из приложения-аутентификатора').fill(totp(secret));
  await page.getByRole('button', { name: 'Войти' }).click();
  await expect(page).toHaveURL(/\/admin\/index\.html$/);
}

async function publicNames(page) {
  const list = await page.request.get('/api/v1/apps?per_page=50');
  return (await list.json()).data.map((app) => app.name);
}

test('catalog manager creates, versions and publishes a listing', async ({ page }) => {
  await signInAdmin(page);
  await page.goto('/admin/apps.html');

  await page.getByRole('button', { name: 'Добавить приложение' }).click();
  const create = page.locator('.panel-dialog');
  await create.getByLabel('Название', { exact: true }).fill(appName);
  await create.getByLabel('Подзаголовок').fill('Все мысли рядом');
  await create.getByLabel('Описание').fill('Спокойное пространство для заметок и идей.');
  await create.getByRole('button', { name: 'Сохранить', exact: true }).click();

  // The editor opens on the created listing (a second dialog on top).
  const editor = page.locator('.panel-dialog').last();
  await expect(editor.locator('h2')).toHaveText(appName);

  // Publish first; adding a version rebuilds the editor DOM.
  await editor.getByLabel('Видимость').selectOption('PUBLISHED');
  await editor.getByRole('button', { name: 'Сохранить', exact: true }).first().click();
  await expect(page.locator('.toast').last()).toContainText('Сохранено');
  await expect.poll(() => publicNames(page)).toContain(appName);

  // Now add a version; it shows up in the public catalog.
  await editor.getByRole('textbox', { name: 'Версия', exact: true }).fill('1.0.0');
  await editor.getByRole('textbox', { name: 'Сборка', exact: true }).fill('1');
  await editor.getByRole('button', { name: 'Добавить версию' }).click();
  await expect(page.locator('.toast').last()).toContainText('Сохранено');
  await expect.poll(async () => {
    const list = await page.request.get('/api/v1/apps?per_page=50');
    return (await list.json()).data.find((app) => app.name === appName)?.latest_version?.version;
  }).toBe('1.0.0');
});

test('a draft listing is hidden from the public catalog', async ({ page }) => {
  await signInAdmin(page);
  await page.goto('/admin/apps.html');

  const draftName = `Черновик ${Date.now()}`;
  await page.getByRole('button', { name: 'Добавить приложение' }).click();
  const create = page.locator('.panel-dialog');
  await create.getByLabel('Название', { exact: true }).fill(draftName);
  await create.getByRole('button', { name: 'Сохранить', exact: true }).click();
  await expect(page.locator('.panel-dialog').last().locator('h2')).toHaveText(draftName);

  expect(await publicNames(page)).not.toContain(draftName);
});

test('adds a publisher', async ({ page }) => {
  await signInAdmin(page);
  await page.goto('/admin/apps.html');

  await page.getByRole('tab', { name: 'Издатели' }).click();
  const panel = page.locator('#publishers');
  await panel.getByLabel('Издатель', { exact: true }).fill(`Студия ${Date.now()}`);
  await panel.getByRole('button', { name: 'Добавить издателя' }).click();
  await expect(page.locator('.toast')).toContainText('Сохранено');
});
