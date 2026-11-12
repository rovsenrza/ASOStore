import { boot, el, errorNotice, formatDate, formatDateTime, newIdempotencyKey } from '../app.js';
import { confirmAction } from '../components/confirm-dialog.js';
import { createDataTable } from '../components/data-table.js';
import { openDialog } from '../components/dialog.js';
import { statusBadge } from '../components/status-badge.js';
import { toast } from '../components/toast.js';
import { deviceLine, openCustomerCard, telegramLink } from '../components/customer-card.js';

const { api, t, can, me } = await boot();
const ROLES = ['customer', 'support', 'catalog_manager', 'admin'];

/* ---------- Tabs ---------- */

const tabs = [...document.querySelectorAll('[role="tab"]')];
const panels = tabs.map((tab) => document.getElementById(tab.getAttribute('aria-controls')));
const tabAllowed = { 'tab-users': can('users.view'), 'tab-codes': can('activation-codes.view'), 'tab-tickets': can('users.view') };

function selectTab(tab) {
  tabs.forEach((other, index) => {
    const selected = other === tab;
    other.setAttribute('aria-selected', String(selected));
    other.tabIndex = selected ? 0 : -1;
    panels[index].hidden = !selected;
  });
}

tabs.forEach((tab) => {
  tab.hidden = !tabAllowed[tab.id];
  tab.addEventListener('click', () => selectTab(tab));
});
selectTab(tabs.find((tab) => !tab.hidden));

/* ---------- Clients: account + access + iPhones in one table ---------- */

const ctx = { api, t, can, me };
const STATES = [
  ['all', 'Все'],
  ['active', 'С доступом'],
  ['ending1', 'Кончается за 24 ч'],
  ['ending3', 'Кончается за 3 дня'],
  ['ending7', 'Кончается за 7 дней'],
  ['expired7', 'Закончилась за 7 дней'],
  ['expired', 'Закончилась'],
  ['unlimited', 'Бессрочно'],
  ['none', 'Никогда не было'],
];

/** «12 дн 5 ч», «5 ч 20 мин», «3 дн назад». */
function leftText(seconds, unlimited) {
  if (unlimited) return 'бессрочно';
  if (seconds === null || seconds === undefined) return 'нет подписки';
  const abs = Math.abs(seconds);
  const days = Math.floor(abs / 86400);
  const hours = Math.floor((abs % 86400) / 3600);
  const minutes = Math.floor((abs % 3600) / 60);
  const text = days ? `${days} дн${hours ? ` ${hours} ч` : ''}` : hours ? `${hours} ч${minutes ? ` ${minutes} мин` : ''}` : `${minutes} мин`;
  return seconds > 0 ? text : `${text} назад`;
}

function accessCell(row) {
  const s = row.seconds_left;
  const tone = row.unlimited ? 'ok' : s === null ? 'neutral' : s <= 0 ? 'error' : s <= 86400 * 3 ? 'warn' : 'ok';
  return el('span', { className: 'stack-tight' },
    el('span', { className: `badge badge--${tone}` }, leftText(s, row.unlimited)),
    row.ends_at ? el('span', { className: 'muted small' }, `до ${formatDateTime(row.ends_at)}`) : null);
}

function clientCell(row) {
  const staff = (row.roles ?? []).filter((r) => r !== 'customer');
  return el('span', { className: 'stack-tight' },
    el('a', { href: '#', onclick: (e) => { e.preventDefault(); openCustomerCard(ctx, row.id, { onChange: () => clients?.reload() }); } }, row.email),
    el('span', { className: 'muted small' }, `№${row.number}`, row.name && row.name !== row.email ? ` · ${row.name}` : '',
      row.status !== 'ACTIVE' ? ' · заблокирован' : '', staff.length ? ` · ${staff.map((r) => t(`roles.${r}`)).join(', ')}` : ''));
}

function devicesCell(row) {
  if (!row.device_list?.length) return el('span', { className: 'muted' }, '—');
  return el('span', { className: 'stack-tight' }, ...row.device_list.map((d) => deviceLine(d)),
    row.devices > row.device_list.length ? el('span', { className: 'muted small' }, `ещё ${row.devices - row.device_list.length}`) : null);
}

const summaryBox = document.querySelector('#clients-summary');
let stateSelect;

function renderSummary(summary) {
  summaryBox.replaceChildren(...STATES.filter(([key]) => key !== 'all').map(([key, label]) => el('button', {
    type: 'button', className: 'stat-chip', onclick: () => selectState(key),
  }, el('span', {}, label), el('strong', {}, String(summary?.[key] ?? 0)))));
}

const clients = can('users.view') && createDataTable({
  container: document.querySelector('#users-table'),
  t,
  perPage: 50,
  caption: 'Клиенты',
  searchLabel: 'Email, имя, №, @telegram, UDID или 4 последних символа',
  filters: [
    { name: 'state', label: 'Доступ', type: 'select', options: STATES },
    { name: 'role', label: t('users.roles'), type: 'select', options: [['', t('users.allRoles')], ...ROLES.map((role) => [role, t(`roles.${role}`)])] },
  ],
  columns: [
    { key: 'email', label: 'Клиент', render: clientCell },
    { key: 'telegram', label: 'Telegram', render: (r) => (r.telegram ? telegramLink(r.telegram) : '—') },
    { key: 'seconds_left', label: 'Доступ', render: accessCell },
    { key: 'device_list', label: 'Устройства · UDID · регистрация', render: devicesCell },
    { key: 'actions', label: '', render: (r) => el('button', { type: 'button', className: 'button', onclick: () => openCustomerCard(ctx, r.id, { onChange: () => clients?.reload() }) }, t('common.open')) },
  ],
  fetchPage: async ({ page, perPage, query, filters, signal }) => {
    const params = new URLSearchParams({ page, per_page: perPage, state: 'all', ...filters });
    if (query) params.set('q', query);
    const { data, meta } = await api.get(`/admin/subscriptions?${params}`, { signal });
    renderSummary(meta.summary);
    return { rows: data, pagination: meta.pagination };
  },
});

stateSelect = document.querySelector('#users-table select');

function selectState(key) {
  if (!stateSelect) return;
  stateSelect.value = key;
  stateSelect.dispatchEvent(new Event('change'));
  history.replaceState(null, '', `?state=${key}`);
}

const initialState = new URLSearchParams(location.search).get('state');
if (initialState && STATES.some(([key]) => key === initialState)) selectState(initialState);
const usersTable = clients;

async function act(request, successMessage, refresh) {
  try {
    await request();
    toast(successMessage, { tone: 'ok' });
    await refresh?.();
    usersTable?.reload();
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
  }
}

const createButton = document.querySelector('#create-user');
createButton.hidden = !can('users.manage');
// «+ Добавить сотрудника» on the bot page links here with ?new=staff.
if (can('users.manage') && new URLSearchParams(location.search).get('new') === 'staff') queueMicrotask(() => createButton.click());
createButton.addEventListener('click', () => {
  const status = el('div');
  const form = el('form', { className: 'stack', noValidate: true },
    status,
    el('p', { className: 'muted' }, t('users.createHint')),
    el('label', { className: 'field' }, t('users.name'), el('input', { name: 'name', required: true, maxLength: 100, autocomplete: 'off' })),
    el('label', { className: 'field' }, t('users.email'), el('input', { name: 'email', type: 'email', required: true, autocomplete: 'off' })),
    el('fieldset', { className: 'role-set' }, el('legend', {}, t('users.roles')),
      ROLES.filter((role) => role !== 'customer').map((role) => el('label', { className: 'check' },
        el('input', { type: 'checkbox', name: 'roles', value: role, checked: role === 'support' }), ` ${t(`roles.${role}`)}`))),
    el('div', { className: 'button-row' }, el('button', { type: 'submit', className: 'button button--primary' }, t('users.create'))));

  const panel = openDialog(t, { title: t('users.create'), body: form });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    const data = new FormData(form);
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      await api.post('/admin/users', { name: data.get('name'), email: data.get('email'), roles: data.getAll('roles') });
      panel.close();
      toast(t('users.created_toast'), { tone: 'ok' });
      usersTable?.reload();
    } catch (error) {
      status.replaceChildren(errorNotice(t, error));
      button.disabled = false;
    }
  });
});

/* ---------- Activation codes ---------- */

const codesTable = can('activation-codes.view') && createDataTable({
  container: document.querySelector('#codes-table'),
  t,
  caption: t('codes.tab'),
  searchLabel: t('codes.code'),
  filters: [
    { name: 'status', label: t('users.status'), type: 'select', options: [['', t('codes.allStatuses')], ...['ISSUED', 'REDEEMED', 'EXPIRED', 'REVOKED'].map((s) => [s, t(`codeStatus.${s}`)])] },
  ],
  columns: [
    { key: 'hint', label: t('codes.code'), className: 'mono', render: (code) => `••••-${code.hint}` },
    { key: 'status', label: t('users.status'), render: (code) => statusBadge(code.status, t(`codeStatus.${code.status}`)) },
    { key: 'plan', label: t('codes.plan') },
    { key: 'duration_days', label: t('codes.duration'), render: (code) => (code.duration_days ? t('codes.days', { count: code.duration_days }) : t('users.unlimited')) },
    { key: 'expires_at', label: t('codes.expires'), render: (code) => formatDate(code.expires_at) },
    { key: 'redeemed_by', label: t('codes.redeemedBy'), render: (code) => (code.redeemed_by ? `${code.redeemed_by.email} · ${formatDate(code.redeemed_at)}` : t('common.none')) },
    { key: 'created_at', label: t('codes.created'), sortable: true, render: (code) => `${formatDate(code.created_at)} · ${code.created_by.email}` },
    { key: 'note', label: t('codes.note') },
    {
      key: 'actions',
      label: '',
      render: (code) => (can('activation-codes.manage') && code.status === 'ISSUED'
        ? el('button', { type: 'button', className: 'button button--danger', onclick: () => revokeCode(code) }, t('codes.revoke'))
        : ''),
    },
  ],
  fetchPage: async ({ page, perPage, query, filters, signal }) => {
    const params = new URLSearchParams({ page, per_page: perPage, ...filters });
    if (query) params.set('q', query.replace(/[^0-9A-Za-z]/g, '').slice(-4));
    const { data, meta } = await api.get(`/admin/activation-codes?${params}`, { signal });
    return { rows: data, pagination: meta.pagination };
  },
});

async function revokeCode(code) {
  const { confirmed, reason } = await confirmAction(t, { title: t('codes.revokeTitle'), message: t('codes.revokeMessage'), danger: true, requireReason: true });
  if (!confirmed) return;
  try {
    await api.post(`/admin/activation-codes/${code.id}/revoke`, { reason });
    toast(t('codes.revoked'), { tone: 'ok' });
    codesTable.reload();
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
  }
}

const generateForm = document.querySelector('#generate-form');
const generateResult = document.querySelector('#generate-result');
generateForm.hidden = !can('activation-codes.manage');
// One key per intended batch: a retried submit cannot create a second batch.
let batchKey = newIdempotencyKey('batch');

generateForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!generateForm.reportValidity()) return;
  const data = new FormData(generateForm);
  const button = generateForm.querySelector('[type="submit"]');
  const body = { count: Number(data.get('count')) };
  if (data.get('duration_days')) body.duration_days = Number(data.get('duration_days'));
  if (data.get('expires_at')) body.expires_at = new Date(data.get('expires_at')).toISOString();
  if (data.get('note')) body.note = data.get('note');

  button.disabled = true;
  generateResult.replaceChildren();
  try {
    const { data: batch } = await api.post('/admin/activation-codes', body, { idempotencyKey: batchKey });
    batchKey = newIdempotencyKey('batch');
    generateForm.reset();
    showBatch(batch);
    codesTable.reload();
  } catch (error) {
    generateResult.replaceChildren(errorNotice(t, error));
  } finally {
    button.disabled = false;
  }
});

function showBatch(batch) {
  const copy = el('button', { type: 'button', className: 'button' }, t('codes.copy'));
  copy.addEventListener('click', async () => {
    await navigator.clipboard.writeText(batch.codes.join('\n'));
    toast(t('codes.copied'), { tone: 'ok' });
  });

  const csv = `code,batch_id\n${batch.codes.map((code) => `${code},${batch.batch_id}`).join('\n')}\n`;
  const download = el('a', {
    className: 'button',
    href: URL.createObjectURL(new Blob([csv], { type: 'text/csv' })),
    download: `activation-codes-${batch.batch_id}.csv`,
  }, t('codes.download'));

  generateResult.replaceChildren(el('section', { className: 'card batch-result', role: 'status' },
    el('h3', {}, `${t('codes.resultTitle')}: ${batch.count}`),
    el('p', { className: 'notice' }, t('codes.resultWarning')),
    el('ol', { className: 'code-list mono' }, batch.codes.map((code) => el('li', {}, code))),
    el('div', { className: 'button-row' }, copy, download)));
}

/* ---------- Support tickets (P8-WEB-01) ---------- */

if (can('users.view')) {
  const tickets = createDataTable({
    container: document.querySelector('#tickets-table'),
    t,
    caption: t('tickets.caption'),
    searchable: false,
    filters: [{ name: 'status', label: t('tickets.status'), type: 'select', options: [['OPEN', t('tickets.open')], ['CLOSED', t('tickets.closed')]] }],
    columns: [
      { key: 'created_at', label: t('tickets.created'), render: (ticket) => formatDateTime(ticket.created_at) },
      { key: 'topic', label: t('tickets.topic'), render: (ticket) => t(`tickets.topics.${ticket.topic}`) },
      { key: 'email', label: t('tickets.email') },
      { key: 'message', label: t('tickets.message'), render: (ticket) => ticket.message.slice(0, 160) },
      { key: 'reference_request_id', label: t('tickets.reference'), className: 'mono', render: (ticket) => ticket.reference_request_id ?? '' },
      { key: 'actions', label: '', render: (ticket) => (ticket.status === 'OPEN' ? el('button', { type: 'button', className: 'button', onclick: async () => {
        const { confirmed, reason } = await confirmAction(t, { title: t('tickets.closeTitle'), message: t('tickets.closeMessage'), requireReason: true });
        if (!confirmed) return;
        await act(() => api.post(`/admin/support-tickets/${ticket.id}/close`, { reason }), t('tickets.closedToast'), () => tickets.reload());
      } }, t('tickets.close')) : '') },
    ],
    fetchPage: async ({ page, filters, signal }) => {
      const params = new URLSearchParams({ page, status: filters.status || 'OPEN' });
      const { data, meta } = await api.get(`/admin/support-tickets?${params}`, { signal });
      return { rows: data, pagination: meta.pagination };
    },
  });
}
