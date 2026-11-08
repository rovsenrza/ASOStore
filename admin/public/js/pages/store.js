import { boot, el, errorNotice, formatDateTime, newIdempotencyKey, setupTabs } from '../app.js';
import { confirmAction } from '../components/confirm-dialog.js';
import { openCustomerCard, orderStatusLabel, rub, telegramLink } from '../components/customer-card.js';
import { createDataTable } from '../components/data-table.js';
import { statusBadge } from '../components/status-badge.js';
import { toast } from '../components/toast.js';

/**
 * The Telegram store from the web panel: orders (confirm / reject manual payments), promo codes,
 * bot customers and their balance, plan prices with the referral share, and broadcasts.
 */
const { api, t, can } = await boot();
const ctx = { api, t, can };
const manage = can('activation-codes.manage');
setupTabs();

async function act(run, ok, after) {
  try {
    await run();
    toast(ok, { tone: 'ok' });
    await after?.();
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
  }
}

/* ---------- Orders ---------- */

const ORDER_STATUSES = ['REVIEW', 'PENDING', 'PAID', 'CANCELLED', 'EXPIRED', 'REJECTED'];

const orders = createDataTable({
  container: document.querySelector('#orders-table'),
  t,
  caption: 'Заказы',
  searchLabel: 'Номер заказа (конец), @telegram, Telegram ID, email, ID платежа',
  filters: [
    { name: 'status', label: 'Статус', type: 'select', options: [['', 'Все статусы'], ...ORDER_STATUSES.map((s) => [s, orderStatusLabel(s)])] },
    { name: 'channel', label: 'Канал', type: 'select', options: [['', 'Бот и сайт'], ['bot', 'Бот'], ['web', 'Сайт']] },
  ],
  columns: [
    { key: 'short', label: 'Заказ', className: 'mono', render: (o) => el('span', { title: o.reference }, o.short) },
    { key: 'status', label: 'Статус', render: (o) => statusBadge(o.status === 'PAID' ? 'ok' : o.status, orderStatusLabel(o.status)) },
    { key: 'channel', label: 'Канал', render: (o) => (o.channel === 'web' ? 'Сайт' : 'Бот') + (o.renewal ? ' · продление' : '') },
    { key: 'telegram', label: 'Покупатель', render: (o) => (o.telegram ? telegramLink(o.telegram) : '—') },
    { key: 'account', label: 'Аккаунт', render: (o) => (o.account
      ? el('a', { href: '#', onclick: (e) => { e.preventDefault(); openCustomerCard(ctx, o.account.id); } }, `${o.account.email} · №${o.account.number}`) : '—') },
    { key: 'duration_days', label: 'Срок', render: (o) => `${o.duration_days} дн` },
    { key: 'amount_due_rub', label: 'К оплате', render: (o) => `${rub(o.amount_due_rub)}${o.discount_rub ? ` (−${rub(o.discount_rub)}${o.promo ? ` ${o.promo}` : ''})` : ''}${o.balance_used_rub ? ` · баланс ${rub(o.balance_used_rub)}` : ''}` },
    { key: 'payment', label: 'Оплата', render: (o) => [o.payment_provider, o.payment_method].filter(Boolean).join(' · ') || '—' },
    { key: 'created_at', label: 'Создан', render: (o) => formatDateTime(o.created_at) },
    { key: 'paid_at', label: 'Оплачен', render: (o) => formatDateTime(o.paid_at) },
    { key: 'actions', label: '', render: (o) => orderActions(o) },
  ],
  fetchPage: async ({ page, perPage, query, filters, signal }) => {
    const params = new URLSearchParams({ page, per_page: perPage, ...filters });
    if (query) params.set('q', query);
    const { data, meta } = await api.get(`/admin/store/orders?${params}`, { signal });
    return { rows: data, pagination: meta.pagination };
  },
});

function orderActions(order) {
  if (!manage || !['REVIEW', 'PENDING'].includes(order.status)) return '';
  const confirm = el('button', { type: 'button', className: 'button button--primary' }, 'Подтвердить оплату');
  confirm.addEventListener('click', async () => {
    const { confirmed, reason } = await confirmAction(t, { title: `Подтвердить оплату заказа ${order.short}?`,
      message: `${rub(order.amount_due_rub)}. Покупатель сразу получит доступ (или код в боте). Отменить нельзя.`, requireReason: true });
    if (!confirmed) return;
    await act(() => api.post(`/admin/store/orders/${order.reference}/confirm`, { reason }, { idempotencyKey: newIdempotencyKey('confirm') }), 'Оплата подтверждена', orders.reload);
  });
  const reject = el('button', { type: 'button', className: 'button button--danger' }, 'Отклонить');
  reject.addEventListener('click', async () => {
    const { confirmed, reason } = await confirmAction(t, { title: `Отклонить заказ ${order.short}?`, message: 'Покупатель получит сообщение в боте.', danger: true, requireReason: true });
    if (!confirmed) return;
    await act(() => api.post(`/admin/store/orders/${order.reference}/reject`, { reason }), 'Заказ отклонён', orders.reload);
  });
  return el('span', { className: 'button-row button-row--tight' }, confirm, reject);
}

/* ---------- Promo codes ---------- */

let plans = [];

async function loadPlans() {
  const { data } = await api.get('/admin/store/settings');
  plans = data.plans;
  return data;
}

function promoForm() {
  const box = document.querySelector('#promo-form');
  if (!manage) return;
  const planBoxes = plans.map((p) => el('label', { className: 'check' }, el('input', { type: 'checkbox', name: 'plan_keys', value: p.key }), ` ${p.months} мес`));
  const form = el('form', { className: 'card generate-form', noValidate: true },
    el('h2', {}, 'Новый промокод'),
    el('div', { className: 'form-grid' },
      el('label', { className: 'field' }, 'Код', el('input', { name: 'code', required: true, maxLength: 32, pattern: '[A-Za-z0-9_-]{3,32}', autocomplete: 'off' })),
      el('label', { className: 'field' }, 'Тип скидки', el('select', { name: 'type' }, new Option('Проценты', 'percent'), new Option('Рубли', 'fixed'))),
      el('label', { className: 'field' }, 'Размер', el('input', { name: 'value', type: 'number', min: 1, required: true, value: 10 })),
      el('label', { className: 'field' }, 'Лимит использований', el('input', { name: 'max_uses', type: 'number', min: 1 })),
      el('label', { className: 'field' }, 'Действует до', el('input', { name: 'expires_at', type: 'date' })),
      el('label', { className: 'field' }, 'Заметка', el('input', { name: 'note', maxLength: 255 }))),
    el('fieldset', { className: 'role-set' }, el('legend', {}, 'Только для тарифов (пусто — для всех)'), planBoxes),
    el('div', { className: 'button-row' }, el('button', { type: 'submit', className: 'button button--primary' }, 'Создать промокод')));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    const data = new FormData(form);
    const body = { code: data.get('code'), type: data.get('type'), value: Number(data.get('value')), plan_keys: data.getAll('plan_keys') };
    if (data.get('max_uses')) body.max_uses = Number(data.get('max_uses'));
    if (data.get('expires_at')) body.expires_at = new Date(`${data.get('expires_at')}T23:59:59`).toISOString();
    if (data.get('note')) body.note = data.get('note');
    await act(() => api.post('/admin/store/promo-codes', body), 'Промокод создан', async () => { form.reset(); await loadPromos(); });
  });
  box.replaceChildren(form);
}

async function loadPromos() {
  const box = document.querySelector('#promo-list');
  try {
    const { data } = await api.get('/admin/store/promo-codes');
    const planName = (key) => plans.find((p) => p.key === key)?.months ?? key;
    box.replaceChildren(data.length ? el('div', { className: 'simple-table' }, el('table', {},
      el('thead', {}, el('tr', {}, ['Код', 'Скидка', 'Использований', 'Выручка', 'Тарифы', 'До', 'Заметка', 'Статус', ''].map((h) => el('th', { scope: 'col' }, h)))),
      el('tbody', {}, data.map((p) => el('tr', {},
        el('td', { className: 'mono' }, p.code),
        el('td', {}, p.label),
        el('td', {}, `${p.usage.paid}${p.max_uses ? ` / ${p.max_uses}` : ''}${p.usage.holding ? ` (+${p.usage.holding} в процессе)` : ''}`),
        el('td', {}, `${rub(p.usage.revenue)} · скидки ${rub(p.usage.discount)}`),
        el('td', {}, p.plan_keys?.length ? p.plan_keys.map(planName).join(', ') + ' мес' : 'все'),
        el('td', {}, formatDateTime(p.expires_at)),
        el('td', {}, p.note ?? ''),
        el('td', {}, statusBadge(p.active ? 'ACTIVE' : 'DISABLED', p.active ? 'Активен' : 'На паузе')),
        el('td', {}, manage ? el('button', { type: 'button', className: 'button', onclick: () => act(() => api.post(`/admin/store/promo-codes/${p.id}/toggle`), p.active ? 'Поставлен на паузу' : 'Включён', loadPromos) }, p.active ? 'Пауза' : 'Включить') : '')))))) : el('p', { className: 'muted' }, 'Промокодов нет.'));
  } catch (error) {
    box.replaceChildren(errorNotice(t, error, loadPromos));
  }
}

/* ---------- Bot customers ---------- */

const customers = createDataTable({
  container: document.querySelector('#customers-table'),
  t,
  caption: 'Покупатели бота',
  searchLabel: '@username, имя или Telegram ID',
  filters: [{ name: 'with_balance', label: 'Баланс', type: 'select', options: [['', 'Все'], ['1', 'С балансом']] }],
  columns: [
    { key: 'telegram', label: 'Telegram', render: (c) => telegramLink(c) },
    { key: 'telegram_user_id', label: 'ID', className: 'mono' },
    { key: 'first_name', label: 'Имя' },
    { key: 'paid_orders', label: 'Покупок' },
    { key: 'balance_rub', label: 'Баланс', render: (c) => rub(c.balance_rub) },
    { key: 'referral_earned_rub', label: 'Заработал на приглашениях', render: (c) => rub(c.referral_earned_rub) },
    { key: 'referrer', label: 'Пришёл от', render: (c) => c.referrer ?? '—' },
    { key: 'blocked_bot', label: 'Бот', render: (c) => statusBadge(c.blocked_bot ? 'BLOCKED' : 'ACTIVE', c.blocked_bot ? 'Заблокировал' : 'Активен') },
    { key: 'last_seen_at', label: 'Был', render: (c) => formatDateTime(c.last_seen_at) },
    { key: 'actions', label: '', render: (c) => (manage ? el('button', { type: 'button', className: 'button', onclick: () => changeBalance(c) }, 'Баланс') : '') },
  ],
  fetchPage: async ({ page, query, filters, signal }) => {
    const params = new URLSearchParams({ page, ...filters });
    if (query) params.set('q', query);
    const { data, meta } = await api.get(`/admin/store/customers?${params}`, { signal });
    return { rows: data, pagination: meta.pagination };
  },
});

async function changeBalance(customer) {
  const raw = window.prompt(`Изменить баланс ${customer.username ? `@${customer.username}` : customer.telegram_user_id} (сейчас ${rub(customer.balance_rub)}).\nСумма в рублях: 200 — начислить, -150 — списать.`);
  if (raw === null) return;
  const amount = Number(String(raw).replace(/[^\d-]/g, ''));
  if (!Number.isInteger(amount) || amount === 0) {
    toast('Нужна сумма целым числом', { tone: 'error' });
    return;
  }
  const { confirmed, reason } = await confirmAction(t, { title: amount > 0 ? `Начислить ${rub(amount)}?` : `Списать ${rub(-amount)}?`,
    message: amount > 0 ? 'Покупатель получит уведомление в боте.' : 'Списание без уведомления.', requireReason: true });
  if (!confirmed) return;
  await act(() => api.post(`/admin/store/customers/${customer.id}/balance`, { amount, reason }, { idempotencyKey: newIdempotencyKey('balance') }), 'Баланс изменён', customers.reload);
}

/* ---------- Prices ---------- */

async function loadPrices() {
  const box = document.querySelector('#prices');
  try {
    const data = await loadPlans();
    const inputs = data.plans.map((p) => el('input', { type: 'number', min: 1, value: p.price, name: p.key, disabled: !manage }));
    const referral = el('input', { type: 'number', min: 0, max: 90, value: data.referral_percent, disabled: !manage });
    const save = el('button', { type: 'button', className: 'button button--primary', hidden: !manage }, 'Сохранить');
    save.addEventListener('click', async () => {
      const prices = Object.fromEntries(inputs.map((input) => [input.name, Number(input.value)]));
      const { confirmed } = await confirmAction(t, { title: 'Сохранить цены?', message: 'Новые цены сразу увидят в боте и на сайте.' });
      if (!confirmed) return;
      await act(() => api.put('/admin/store/settings', { prices, referral_percent: Number(referral.value) }), 'Цены сохранены', loadPrices);
    });
    box.replaceChildren(el('section', { className: 'card stack' },
      data.mock_payments ? el('p', { className: 'notice' }, 'Платежи в тестовом режиме (mock).') : '',
      el('div', { className: 'simple-table' }, el('table', {},
        el('thead', {}, el('tr', {}, ['Тариф', 'Дней', 'Цена, ₽', 'Экономия vs помесячно'].map((h) => el('th', { scope: 'col' }, h)))),
        el('tbody', {}, data.plans.map((p, i) => el('tr', {}, el('td', {}, `${p.months} мес`), el('td', {}, String(p.days)), el('td', {}, inputs[i]), el('td', {}, `${p.saving_percent}%`)))))),
      el('label', { className: 'field' }, 'Реферальный бонус, % от покупки приглашённого', referral),
      el('div', { className: 'button-row' }, save)));
  } catch (error) {
    box.replaceChildren(errorNotice(t, error, loadPrices));
  }
}

/* ---------- Broadcast ---------- */

async function loadBroadcasts() {
  const box = document.querySelector('#broadcast');
  try {
    const { data } = await api.get('/admin/store/broadcasts');
    const text = el('textarea', { rows: 6, maxLength: 3500, placeholder: 'Текст рассылки всем, кто не заблокировал бота' });
    const send = el('button', { type: 'button', className: 'button button--primary', hidden: !manage }, `Отправить ${data.recipients} получателям`);
    send.addEventListener('click', async () => {
      if (!text.value.trim()) return;
      const { confirmed } = await confirmAction(t, { title: `Отправить рассылку ${data.recipients} получателям?`,
        message: 'Сначала сообщение придёт первому админу бота, затем всем покупателям. Остановить рассылку нельзя.', danger: true });
      if (!confirmed) return;
      send.disabled = true;
      await act(() => api.post('/admin/store/broadcasts', { text: text.value.trim() }, { idempotencyKey: newIdempotencyKey('broadcast') }), 'Рассылка запущена', loadBroadcasts);
      send.disabled = false;
    });
    box.replaceChildren(
      manage ? el('section', { className: 'card stack' }, el('h2', {}, 'Новая рассылка'), text, el('div', { className: 'button-row' }, send)) : '',
      el('section', { className: 'card' }, el('h2', {}, 'Последние рассылки'),
        data.items.length ? el('div', { className: 'simple-table' }, el('table', {},
          el('thead', {}, el('tr', {}, ['Когда', 'Статус', 'Всего', 'Доставлено', 'Не доставлено', 'Закончена'].map((h) => el('th', { scope: 'col' }, h)))),
          el('tbody', {}, data.items.map((b) => el('tr', {}, el('td', {}, formatDateTime(b.created_at)), el('td', {}, statusBadge(b.status === 'DONE' ? 'ok' : b.status, b.status)),
            el('td', {}, String(b.total)), el('td', {}, String(b.sent)), el('td', {}, String(b.failed)), el('td', {}, formatDateTime(b.finished_at))))))) : el('p', { className: 'muted' }, 'Рассылок не было.')),
      el('button', { type: 'button', className: 'button button--ghost', onclick: loadBroadcasts }, 'Обновить'));
  } catch (error) {
    box.replaceChildren(errorNotice(t, error, loadBroadcasts));
  }
}

await loadPrices();
promoForm();
loadPromos();
loadBroadcasts();
void orders;
