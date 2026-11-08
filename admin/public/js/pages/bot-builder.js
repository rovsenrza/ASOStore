import { boot, el, errorNotice, formatDateTime, setupTabs } from '../app.js';
import { confirmAction } from '../components/confirm-dialog.js';
import { statusBadge } from '../components/status-badge.js';
import { toast } from '../components/toast.js';

/**
 * Bot builder: the admin adds pages to the Telegram bot (a menu button, a /command and/or
 * keywords that answer with a text and an optional link), hides standard buttons, and decides
 * who else is a bot admin. Saved to store settings: the bot uses it on the next message.
 */
const { api, t, can } = await boot();
const canEdit = can('activation-codes.manage');
const canAdmins = can('users.manage');
setupTabs();

const TEMPLATES = [
  { label: '❓ Частые вопросы', command: 'faq', keywords: ['вопрос', 'faq'], text: '<b>❓ Частые вопросы</b>\n\n<b>Сколько ждать подключения?</b>\nОбычно несколько минут, иногда Apple обрабатывает устройство до 72 часов — это время мы добавим к подписке.\n\n<b>Как установить приложение?</b>\nОткройте сайт на iPhone в Safari и нажмите «Установить».' },
  { label: '⭐ Отзывы', command: 'reviews', keywords: ['отзыв'], text: '<b>⭐ Отзывы покупателей</b>\n\nЧитайте отзывы и оставляйте свой в нашем канале.', url: 'https://t.me/', url_label: 'Открыть отзывы' },
  { label: '🔥 Акция', command: 'sale', keywords: ['акци', 'скидк'], text: '<b>🔥 Акция!</b>\n\nСкидка на годовую подписку до конца недели. Нажмите «Купить подписку» в меню.' },
  { label: '📞 Контакты', command: 'contacts', keywords: ['контакт', 'связаться'], text: '<b>📞 Контакты</b>\n\nПишите в поддержку — отвечаем каждый день.', url: 'https://t.me/', url_label: 'Написать' },
  { label: '📱 Список приложений', command: 'apps', keywords: ['какие приложения', 'список приложений'], text: '<b>📱 Что есть в каталоге</b>\n\nБанки, маркетплейсы, госуслуги, музыка, видео и игры.', url: 'https://ruappstore.com/', url_label: 'Смотреть каталог' },
];

let state = { blocks: [], hidden: [], hideable: [], reserved: [] };
const builderBox = document.querySelector('#bot-builder');

async function loadBuilder() {
  try {
    const { data } = await api.get('/admin/bot-builder');
    state = { blocks: data.blocks.map((b) => ({ ...b })), hidden: [...data.hidden_menu_items], hideable: data.hideable, reserved: data.reserved_commands };
    renderBuilder();
  } catch (error) {
    builderBox.replaceChildren(errorNotice(t, error, loadBuilder));
  }
}

function blockCard(block, index) {
  const field = (label, input, hint) => el('label', { className: 'field' }, label, input, hint ? el('span', { className: 'hint' }, hint) : '');
  const bind = (input, key, map = (v) => v) => {
    input.addEventListener('input', () => { block[key] = map(input.type === 'checkbox' ? input.checked : input.value); renderMenuPreview(); });
    return input;
  };
  const label = bind(el('input', { value: block.label ?? '', maxLength: 40, disabled: !canEdit, placeholder: '❓ Частые вопросы' }), 'label');
  const text = bind(el('textarea', { rows: 6, maxLength: 3500, disabled: !canEdit, value: block.text ?? '' }), 'text');
  const url = bind(el('input', { type: 'url', value: block.url ?? '', disabled: !canEdit, placeholder: 'https://… (необязательно)' }), 'url', (v) => v || null);
  const urlLabel = bind(el('input', { value: block.url_label ?? '', maxLength: 40, disabled: !canEdit, placeholder: 'Открыть' }), 'url_label', (v) => v || null);
  const command = bind(el('input', { value: block.command ? `/${block.command}` : '', maxLength: 33, disabled: !canEdit, placeholder: '/faq' }), 'command', (v) => v.replace(/^\//, '') || null);
  const keywords = bind(el('input', { value: (block.keywords ?? []).join(', '), disabled: !canEdit, placeholder: 'вопрос, faq' }), 'keywords',
    (v) => v.split(',').map((k) => k.trim()).filter(Boolean));
  const inMenu = bind(el('input', { type: 'checkbox', checked: block.in_menu ?? true, disabled: !canEdit }), 'in_menu');
  const enabled = bind(el('input', { type: 'checkbox', checked: block.enabled ?? true, disabled: !canEdit }), 'enabled');

  const move = (delta) => {
    const to = index + delta;
    if (to < 0 || to >= state.blocks.length) return;
    [state.blocks[index], state.blocks[to]] = [state.blocks[to], state.blocks[index]];
    renderBuilder();
  };
  const tools = canEdit ? el('div', { className: 'button-row button-row--tight' },
    el('button', { type: 'button', className: 'button', onclick: () => move(-1), disabled: index === 0 }, '↑ Выше'),
    el('button', { type: 'button', className: 'button', onclick: () => move(1), disabled: index === state.blocks.length - 1 }, '↓ Ниже'),
    el('button', { type: 'button', className: 'button', onclick: () => preview(block) }, '👁 Прислать себе в Telegram'),
    el('button', { type: 'button', className: 'button button--danger', onclick: async () => {
      const { confirmed } = await confirmAction(t, { title: `Удалить раздел «${block.label}»?`, message: 'Кнопка, команда и ключевые слова пропадут из бота после сохранения.', danger: true });
      if (!confirmed) return;
      state.blocks.splice(index, 1);
      renderBuilder();
    } }, 'Удалить')) : '';

  return el('article', { className: 'card stack bot-block' },
    el('div', { className: 'section-head' }, el('h3', {}, `${index + 1}. ${block.label || 'Новый раздел'}`),
      statusBadge(block.enabled === false ? 'DISABLED' : 'ACTIVE', block.enabled === false ? 'выключен' : 'включён'), tools),
    el('div', { className: 'form-grid' },
      field('Текст кнопки в меню', label),
      field('Команда', command, 'Пользователь пишет её боту, она появится в меню «/».'),
      field('Ключевые слова (через запятую)', keywords, 'Если сообщение содержит слово — бот ответит этим разделом.')),
    field('Текст ответа (HTML: <b>, <i>, <u>, <s>, <a href="…">, <code>, <blockquote>)', text),
    el('div', { className: 'form-grid' }, field('Ссылка-кнопка под текстом', url), field('Надпись на ссылке', urlLabel)),
    el('div', { className: 'button-row' },
      el('label', { className: 'check' }, inMenu, ' Показывать кнопку в главном меню'),
      el('label', { className: 'check' }, enabled, ' Раздел включён')));
}

async function preview(block) {
  try {
    const { data } = await api.post('/admin/bot-builder/preview', { label: block.label, text: block.text, url: block.url || null, url_label: block.url_label || null });
    toast(`Отправлено администратору ${data.sent_to}`, { tone: 'ok' });
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
  }
}

const menuPreview = el('div', { className: 'bot-menu-preview' });

function renderMenuPreview() {
  const hidden = new Set(state.hidden);
  const rows = [['🛍 Купить подписку'], ['🔄 Продлить подписку']];
  const account = [!hidden.has('profile') && '👤 Профиль', !hidden.has('invite') && '👥 Пригласить друга'].filter(Boolean);
  if (account.length) rows.push(account);
  const custom = state.blocks.filter((b) => b.enabled !== false && b.in_menu !== false).map((b) => b.label || '…');
  for (let i = 0; i < custom.length; i += 2) rows.push(custom.slice(i, i + 2));
  const info = [!hidden.has('help') && '📖 Инструкция', !hidden.has('news') && '📰 Новости'].filter(Boolean);
  if (info.length) rows.push(info);
  if (!hidden.has('support')) rows.push(['💬 Поддержка']);
  if (!hidden.has('legal')) rows.push(['🔒 Конфиденциальность', '📄 Соглашение']);
  menuPreview.replaceChildren(el('p', { className: 'muted' }, 'Так будет выглядеть главное меню:'),
    ...rows.map((row) => el('div', { className: 'bot-menu-row' }, row.map((label) => el('span', { className: 'bot-menu-button' }, label)))));
}

function renderBuilder() {
  const hiddenBoxes = state.hideable.map((item) => {
    const box = el('input', { type: 'checkbox', checked: !state.hidden.includes(item.key), disabled: !canEdit });
    box.addEventListener('change', () => {
      state.hidden = box.checked ? state.hidden.filter((k) => k !== item.key) : [...state.hidden, item.key];
      renderMenuPreview();
    });
    return el('label', { className: 'check' }, box, ` ${item.label}`);
  });

  const add = (template = {}) => {
    state.blocks.push({ label: '', text: '', url: null, url_label: null, command: null, keywords: [], in_menu: true, enabled: true, ...structuredClone(template) });
    renderBuilder();
    builderBox.querySelector('.bot-block:last-of-type input')?.focus();
  };

  const save = el('button', { type: 'button', className: 'button button--primary', hidden: !canEdit }, 'Сохранить всё в бот');
  save.addEventListener('click', async () => {
    save.disabled = true;
    try {
      const { data } = await api.put('/admin/bot-builder', { blocks: state.blocks, hidden_menu_items: state.hidden });
      state.blocks = data.blocks.map((b) => ({ ...b }));
      toast('Сохранено. Бот уже показывает изменения.', { tone: 'ok' });
      renderBuilder();
    } catch (error) {
      toast(t.error(error), { tone: 'error' });
    } finally {
      save.disabled = false;
    }
  });

  renderMenuPreview();
  builderBox.replaceChildren(el('div', { className: 'bot-builder-layout' },
    el('div', { className: 'stack' },
      el('p', { className: 'muted' }, canEdit
        ? 'Добавляйте свои разделы в бота без программиста: кнопка в меню, команда (например /faq) и ключевые слова, на которые бот отвечает. Нажмите «Сохранить всё в бот» — изменения сразу появятся у пользователей.'
        : 'Просмотр. Редактирование доступно роли «Администратор».'),
      canEdit ? el('div', { className: 'button-row' }, el('button', { type: 'button', className: 'button button--primary', onclick: () => add() }, '+ Новый раздел'),
        el('span', { className: 'muted' }, 'или шаблон:'), ...TEMPLATES.map((tpl) => el('button', { type: 'button', className: 'button button--ghost', onclick: () => add(tpl) }, tpl.label))) : '',
      ...(state.blocks.length ? state.blocks.map(blockCard) : [el('p', { className: 'muted' }, 'Своих разделов пока нет.')]),
      el('section', { className: 'card stack' }, el('h3', {}, 'Стандартные кнопки меню'),
        el('p', { className: 'muted' }, '«Купить» и «Продлить» показываются всегда. Остальные можно скрыть.'), el('div', { className: 'button-row' }, hiddenBoxes)),
      el('div', { className: 'button-row' }, save)),
    el('aside', { className: 'card' }, menuPreview)));
}

/* ---------- Admins ---------- */

const adminsBox = document.querySelector('#bot-admins');

async function loadAdmins() {
  try {
    const [{ data: botAdmins }, ...staffLists] = await Promise.all([
      api.get('/admin/bot-admins'),
      ...(can('users.view') ? ['admin', 'support', 'catalog_manager'].map((role) => api.get(`/admin/users?role=${role}&per_page=100`)) : []),
    ]);
    const staff = new Map();
    for (const list of staffLists) for (const user of list.data) staff.set(user.id, user);

    const query = el('input', { placeholder: '@username или Telegram ID', disabled: !canAdmins });
    const addBtn = el('button', { type: 'button', className: 'button button--primary', disabled: !canAdmins }, 'Сделать админом бота');
    addBtn.addEventListener('click', async () => {
      if (!query.value.trim()) return;
      const { confirmed, reason } = await confirmAction(t, { title: `Сделать ${query.value.trim()} администратором бота?`,
        message: 'Он сможет подтверждать оплаты, менять цены, промокоды, балансы и делать рассылки прямо в боте (/admin).', requireReason: true });
      if (!confirmed) return;
      try {
        await api.post('/admin/bot-admins', { query: query.value.trim(), reason });
        toast('Администратор добавлен. Пусть напишет боту /admin.', { tone: 'ok' });
        loadAdmins();
      } catch (error) {
        toast(t.error(error), { tone: 'error' });
      }
    });

    const remove = async (admin) => {
      const { confirmed } = await confirmAction(t, { title: `Убрать ${admin.username ? `@${admin.username}` : admin.telegram_user_id} из админов бота?`, message: 'Он больше не увидит /admin.', danger: true });
      if (!confirmed) return;
      try {
        await api.delete(`/admin/bot-admins/${admin.telegram_user_id}`);
        toast('Убран из администраторов', { tone: 'ok' });
        loadAdmins();
      } catch (error) {
        toast(t.error(error), { tone: 'error' });
      }
    };

    const roleNames = { admin: 'Администратор', support: 'Поддержка', catalog_manager: 'Каталог' };
    adminsBox.replaceChildren(
      el('section', { className: 'card stack' }, el('h2', {}, 'Администраторы Telegram-бота'),
        el('p', { className: 'muted' }, 'Видят /admin в боте и получают уведомления о заказах, ожиданиях и ошибках. Человек должен хоть раз открыть бота, чтобы его нашли по @username.'),
        el('div', { className: 'simple-table' }, el('table', {},
          el('thead', {}, el('tr', {}, ['Telegram', 'ID', 'Открывал бота', 'Где задан', ''].map((h) => el('th', { scope: 'col' }, h)))),
          el('tbody', {}, botAdmins.map((a) => el('tr', {},
            el('td', {}, a.username ? el('a', { href: `https://t.me/${a.username}`, target: '_blank', rel: 'noopener' }, `@${a.username}`) : (a.first_name ?? '—')),
            el('td', { className: 'mono' }, String(a.telegram_user_id)),
            el('td', {}, a.started_bot ? 'да' : 'нет'),
            el('td', {}, a.locked ? 'на сервере (.env)' : 'в панели'),
            el('td', {}, !a.locked && canAdmins ? el('button', { type: 'button', className: 'button button--danger', onclick: () => remove(a) }, 'Убрать') : '')))))),
        canAdmins ? el('div', { className: 'form-inline' }, el('label', { className: 'field' }, 'Новый админ бота', query), addBtn) : ''),
      can('users.view') ? el('section', { className: 'card stack' }, el('h2', {}, 'Сотрудники панели'),
        el('p', { className: 'muted' }, 'Вход в эту панель. Роли: Администратор — всё; Поддержка — смотреть клиентов, устройства и заказы; Каталог — приложения и публикация.'),
        el('div', { className: 'simple-table' }, el('table', {},
          el('thead', {}, el('tr', {}, ['Имя', 'Email', 'Роли', '2FA', 'Последний вход', 'Статус'].map((h) => el('th', { scope: 'col' }, h)))),
          el('tbody', {}, [...staff.values()].map((u) => el('tr', {},
            el('td', {}, u.name), el('td', {}, u.email), el('td', {}, u.roles.filter((r) => r !== 'customer').map((r) => roleNames[r] ?? r).join(', ')),
            el('td', {}, u.totp_enabled ? 'да' : 'нет'), el('td', {}, formatDateTime(u.last_login_at)),
            el('td', {}, statusBadge(u.status, u.status === 'ACTIVE' ? 'Активен' : 'Заблокирован'))))))),
        canAdmins ? el('div', { className: 'button-row' }, el('a', { className: 'button button--primary', href: 'users.html?new=staff' }, '+ Добавить сотрудника'),
          el('span', { className: 'muted' }, 'Роли меняются в «Пользователи» → «Учётная запись».')) : '') : '');
  } catch (error) {
    adminsBox.replaceChildren(errorNotice(t, error, loadAdmins));
  }
}

loadBuilder();
loadAdmins();
