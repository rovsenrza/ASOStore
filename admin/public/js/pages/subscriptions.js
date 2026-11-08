import { boot, el, formatDateTime } from '../app.js';
import { openCustomerCard, telegramLink } from '../components/customer-card.js';
import { createDataTable } from '../components/data-table.js';

/**
 * Subscription monitor: how much access every customer has left, soonest first,
 * with counts per window (ends in 24 h / 3 / 7 days, ended, unlimited).
 */
const { api, t, can } = await boot();
const ctx = { api, t, can };

const STATES = [
  ['active', 'С доступом'],
  ['ending1', 'Кончается за 24 ч'],
  ['ending3', 'Кончается за 3 дня'],
  ['ending7', 'Кончается за 7 дней'],
  ['expired7', 'Закончилась за 7 дней'],
  ['expired', 'Закончилась (все)'],
  ['unlimited', 'Бессрочно'],
  ['all', 'Все'],
];

/** «12 дн 5 ч», «5 ч 20 мин», «закончилась 3 дн назад». */
export function leftText(seconds, unlimited) {
  if (unlimited) return 'бессрочно';
  if (seconds === null || seconds === undefined) return '—';
  const abs = Math.abs(seconds);
  const days = Math.floor(abs / 86400);
  const hours = Math.floor((abs % 86400) / 3600);
  const minutes = Math.floor((abs % 3600) / 60);
  const text = days ? `${days} дн${hours ? ` ${hours} ч` : ''}` : hours ? `${hours} ч${minutes ? ` ${minutes} мин` : ''}` : `${minutes} мин`;
  return seconds > 0 ? text : `закончилась ${text} назад`;
}

function leftBadge(row) {
  const s = row.seconds_left;
  const tone = row.unlimited ? 'ok' : s <= 0 ? 'error' : s <= 86400 * 3 ? 'warn' : 'ok';
  return el('span', { className: `badge badge--${tone}` }, leftText(s, row.unlimited));
}

const summaryBox = document.querySelector('#sub-summary');
let stateSelect;

function renderSummary(summary) {
  summaryBox.replaceChildren(...STATES.map(([key, label]) => {
    const card = el('a', { className: 'stat-card', href: `?state=${key}` },
      el('span', { className: 'stat-label' }, label), el('strong', { className: 'stat-value' }, String(summary[key] ?? 0)));
    card.addEventListener('click', (event) => {
      event.preventDefault();
      selectState(key);
    });
    return card;
  }));
}

const table = createDataTable({
  container: document.querySelector('#subs-table'),
  t,
  perPage: 50,
  caption: 'Подписки',
  searchLabel: 'Email, имя, № аккаунта, @telegram',
  filters: [{ name: 'state', label: 'Срок', type: 'select', options: STATES }],
  columns: [
    { key: 'number', label: '№', className: 'mono' },
    { key: 'email', label: 'Клиент', render: (r) => el('a', { href: '#', onclick: (e) => { e.preventDefault(); openCustomerCard(ctx, r.id, { onChange: table.reload }); } }, r.name ? `${r.email} · ${r.name}` : r.email) },
    { key: 'telegram', label: 'Telegram', render: (r) => (r.telegram ? telegramLink(r.telegram) : '—') },
    { key: 'devices', label: 'Устройств' },
    { key: 'seconds_left', label: 'Осталось', render: leftBadge },
    { key: 'ends_at', label: 'Кончается', render: (r) => (r.unlimited ? '—' : formatDateTime(r.ends_at)) },
    { key: 'started_at', label: 'С', render: (r) => formatDateTime(r.started_at) },
  ],
  fetchPage: async ({ page, perPage, query, filters, signal }) => {
    const params = new URLSearchParams({ page, per_page: perPage, ...filters });
    if (query) params.set('q', query);
    const { data, meta } = await api.get(`/admin/subscriptions?${params}`, { signal });
    renderSummary(meta.summary);
    return { rows: data, pagination: meta.pagination };
  },
});

stateSelect = document.querySelector('#subs-table select');

function selectState(key) {
  if (!stateSelect) return;
  stateSelect.value = key;
  stateSelect.dispatchEvent(new Event('change'));
  history.replaceState(null, '', `?state=${key}`);
}

const initial = new URLSearchParams(location.search).get('state');
if (initial && initial !== 'active' && STATES.some(([key]) => key === initial)) selectState(initial);
