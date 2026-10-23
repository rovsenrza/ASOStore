import { boot, el, errorNotice } from '../app.js';
import { toast } from '../components/toast.js';

/**
 * Edit the Telegram bot's greeting, brand name and banner image from the admin panel.
 * Values are saved to the store settings and the bot uses them on its next update — no deploy.
 */
const { api, t, can } = await boot();
const box = document.querySelector('#bot');
const canEdit = can('activation-codes.manage');

const greeting = el('textarea', {
  className: 'input', rows: 8, disabled: !canEdit,
  placeholder: 'Оставьте пустым — бот покажет стандартное приветствие с ценой и брендом.',
});
const brand = el('input', { className: 'input', type: 'text', maxLength: 64, disabled: !canEdit, placeholder: 'Ru App Store' });
const bannerImg = el('img', { className: 'bot-banner', alt: 'Баннер бота' });
const bannerMeta = el('p', { className: 'muted' });
const file = el('input', { type: 'file', accept: 'image/png,image/jpeg', disabled: !canEdit });
const uploadBtn = el('button', { type: 'button', className: 'button', disabled: !canEdit }, 'Загрузить баннер');
const resetBtn = el('button', { type: 'button', className: 'button button--line', disabled: !canEdit }, 'Сбросить к стандартному');
const saveBtn = el('button', { type: 'button', className: 'button button--primary', disabled: !canEdit }, 'Сохранить');
const status = el('p', { className: 'muted' });

function paint(data) {
  greeting.value = data.welcome_text ?? '';
  brand.value = data.brand ?? '';
  bannerImg.src = data.banner_url;
  bannerMeta.textContent = data.banner_is_default
    ? 'Сейчас показывается стандартный баннер.'
    : 'Сейчас показывается загруженный баннер.';
  resetBtn.hidden = data.banner_is_default || !canEdit;
}

async function reload() {
  const { data } = await api.get('/admin/bot-content');
  paint(data);
}

saveBtn.addEventListener('click', async () => {
  saveBtn.disabled = true;
  try {
    const { data } = await api.put('/admin/bot-content', {
      welcome_text: greeting.value.trim() === '' ? null : greeting.value,
      brand: brand.value.trim() === '' ? null : brand.value,
    });
    paint(data);
    toast('Сохранено. Бот подхватит изменения при следующем сообщении.', { tone: 'success' });
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
  } finally {
    saveBtn.disabled = !canEdit;
  }
});

uploadBtn.addEventListener('click', async () => {
  const chosen = file.files?.[0];
  if (!chosen) { toast('Выберите файл изображения (JPEG или PNG).', { tone: 'error' }); return; }
  const form = new FormData();
  form.append('banner', chosen);
  uploadBtn.disabled = true;
  try {
    const { data } = await api.post('/admin/bot-content/banner', form);
    file.value = '';
    paint(data);
    toast('Баннер обновлён.', { tone: 'success' });
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
  } finally {
    uploadBtn.disabled = !canEdit;
  }
});

resetBtn.addEventListener('click', async () => {
  resetBtn.disabled = true;
  try {
    const { data } = await api.delete('/admin/bot-content/banner');
    paint(data);
    toast('Вернули стандартный баннер.', { tone: 'success' });
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
  } finally {
    resetBtn.disabled = !canEdit;
  }
});

box.replaceChildren(
  el('p', { className: 'muted' }, canEdit
    ? 'Меняйте приветствие, бренд и баннер бота. Изменения применяются сразу, без выката.'
    : 'Просмотр. Редактирование доступно роли «Администратор».'),
  el('label', { className: 'field' }, 'Приветствие (можно HTML: <b>, <i>, <a>)', greeting),
  el('label', { className: 'field' }, 'Название бренда', brand),
  el('div', { className: 'stack' }, saveBtn),
  el('hr'),
  el('h2', {}, 'Баннер'),
  el('div', { className: 'bot-banner-wrap' }, bannerImg),
  bannerMeta,
  canEdit ? el('div', { className: 'field' }, file) : el('span'),
  canEdit ? el('div', { className: 'row' }, uploadBtn, resetBtn) : el('span'),
  status,
);

try {
  await reload();
} catch (error) {
  box.replaceChildren(errorNotice(t, error, () => location.reload()));
}
