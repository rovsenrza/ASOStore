import { boot, el, errorNotice, formatDateTime } from '../app.js';
import { hoursText, openCustomerCard, rub } from '../components/customer-card.js';
import { statusBadge } from '../components/status-badge.js';

const { api, t, can } = await boot();
const ctx = { api, t, can };
const health = document.querySelector('#widget-health');

async function loadHealth() {
  const value = health.querySelector('.value');
  const hint = health.querySelector('.hint');
  value.textContent = '…';
  try {
    const { data } = await api.get('/health');
    value.replaceChildren(statusBadge('ok', t('status.ok')));
    hint.textContent = `API ${data.version} · ${new Date(data.time).toLocaleString('ru-RU')}`;
  } catch (error) {
    value.replaceChildren(statusBadge('failed', t('status.failed')));
    hint.textContent = t.error(error) + (error.requestId ? ` · ${error.requestId}` : '');
  }
}

// Free device slots of the primary team, the first number to watch (FULL_PLAN §12).
async function loadQuota() {
  const widget = document.querySelector('#widget-quota');
  if (!can('teams.view')) return;
  widget.hidden = false;
  const value = widget.querySelector('.value');
  const hint = widget.querySelector('.hint');
  try {
    const { data, meta } = await api.get('/admin/apple-teams');
    const primary = data.find((team) => team.is_primary) ?? data[0];
    if (!primary) {
      value.textContent = '—';
      hint.textContent = t('teams.empty');
      return;
    }
    const iphone = primary.quotas.find((quota) => quota.family === 'IPHONE');
    value.replaceChildren(iphone ? `${iphone.remaining} / ${iphone.limit}` : '—');
    hint.textContent = `${primary.apple_team_id} · iPhone${meta.pending_assignments ? ` · ${t('teams.blockingPending', { count: meta.pending_assignments })}` : ''}`;
    if (iphone && iphone.remaining === 0) value.append(' ', statusBadge('BLOCKED', t('teams.blockingTitle')));
  } catch (error) {
    value.replaceChildren(statusBadge('failed', t('status.failed')));
    hint.textContent = t.error(error);
  }
}

// FULL_PLAN §14 metrics, from the latest scheduler snapshot (P8-OPS-02).
async function loadMetrics() {
  if (!can('jobs.view')) return;
  const panel = document.querySelector('#metrics-panel');
  const list = document.querySelector('#metrics');
  panel.hidden = false;
  try {
    const { data } = await api.get('/admin/metrics');
    if (!data.length) {
      list.replaceChildren(Object.assign(document.createElement('p'), { className: 'muted', textContent: t('metrics.empty') }));
      return;
    }
    const format = (metric) => {
      if (metric.name.endsWith('_rate')) return `${Math.round(metric.value * 1000) / 10} %`;
      if (metric.name.endsWith('_bytes')) return `${(metric.value / 1024 ** 3).toFixed(2)} ГБ`;
      return String(Math.round(metric.value * 10) / 10);
    };
    list.replaceChildren(...data.map((metric) => {
      const item = document.createElement('div');
      const term = document.createElement('dt');
      const labels = metric.labels ? ` (${Object.values(metric.labels).join(' · ')})` : '';
      term.textContent = (t.has(`metrics.names.${metric.name}`) ? t(`metrics.names.${metric.name}`) : metric.name) + labels;
      const value = document.createElement('dd');
      value.textContent = format(metric);
      item.append(term, value);
      return item;
    }));
  } catch (error) {
    list.replaceChildren(Object.assign(document.createElement('p'), { textContent: t.error(error) }));
  }
}

// Signed builds are per-device copies; the server removes idle ones every ten minutes.
async function loadStorage() {
  if (!can('jobs.view')) return;
  const widget = document.querySelector('#widget-storage');
  widget.hidden = false;
  const value = widget.querySelector('.value');
  const gb = (bytes) => `${(bytes / 1024 ** 3).toFixed(1).replace('.', ',')} ГБ`;
  try {
    const { data } = await api.get('/admin/storage');
    const { bytes, budget_bytes: budget, by_kind: kinds } = data.signed_builds;
    value.textContent = `${gb(bytes)} из ${gb(budget)}`;
    widget.querySelector('progress').value = budget ? Math.min(100, Math.round((bytes / budget) * 100)) : 0;
    widget.querySelector('[data-storage-kinds]').textContent = `Подготовлено заранее: ${kinds.warm.count} · ждут установки: ${kinds.ready.count} · установлено: ${kinds.delivered.count}`;
    const lastRun = data.last_run ? ` · очистка ${new Date(data.last_run.at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}` : '';
    widget.querySelector('[data-storage-other]').textContent = `Оригиналы: ${gb(data.originals.bytes)} · диск свободен: ${gb(data.disk.free_bytes)}${lastRun}`;
  } catch (error) {
    value.replaceChildren(statusBadge('failed', t('status.failed')));
    widget.querySelector('[data-storage-kinds]').textContent = t.error(error);
  }
}

// Operator overview: Apple waits, team load, subscriptions running out, sales.
async function loadOverview() {
  if (!can('devices.view')) return;
  const box = document.querySelector('#overview');
  box.hidden = false;
  box.replaceChildren(el('p', { className: 'muted' }, 'Загрузка обзора…'));
  try {
    const { data } = await api.get('/admin/overview');
    const d = data.devices;
    const w = data.waiting;
    const s = data.subscriptions;
    const sales = data.sales;
    const stat = (label, value, hint, href) => el(href ? 'a' : 'div', { className: 'stat-card', ...(href ? { href } : {}) },
      el('span', { className: 'stat-label' }, label), el('strong', { className: 'stat-value' }, String(value)), el('span', { className: 'muted' }, hint ?? ''));

    const buckets = [['under_1d', '< 1 дня'], ['1_3d', '1–3 дня'], ['3_7d', '3–7 дней'], ['7_14d', '7–14 дней'], ['over_14d', '> 14 дней']];
    const longest = el('div', { className: 'simple-table' }, el('table', {},
      el('thead', {}, el('tr', {}, ['Клиент', 'Устройство', 'Команда', 'Ждёт', 'С', ''].map((h) => el('th', { scope: 'col' }, h)))),
      el('tbody', {}, w.longest.map((row) => el('tr', { className: row.hours >= 72 ? 'row--late' : '' },
        el('td', {}, row.user ? `${row.user.email} · №${row.user.number}` : '—'),
        el('td', {}, row.device ?? '—'),
        el('td', { className: 'mono' }, row.team ?? '—'),
        el('td', {}, hoursText(row.hours)),
        el('td', {}, formatDateTime(row.since)),
        el('td', {}, row.user ? el('button', { type: 'button', className: 'button', onclick: () => openCustomerCard(ctx, row.user.id, { onChange: loadOverview }) }, 'Карточка') : ''))))));

    const teams = el('div', { className: 'simple-table' }, el('table', {},
      el('thead', {}, el('tr', {}, ['Команда', 'Статус', 'Мест в Apple', 'Работают', 'Ждут Apple', 'Отключены'].map((h) => el('th', { scope: 'col' }, h)))),
      el('tbody', {}, data.teams.map((team) => el('tr', {},
        el('td', {}, el('span', { className: 'mono' }, team.apple_team_id), ` ${team.name ?? ''}${team.is_primary ? ' · основная' : ''}`),
        el('td', {}, statusBadge(team.status, team.status)),
        el('td', {}, `${team.slots_used} / 100`),
        el('td', {}, String(team.eligible)),
        el('td', {}, team.pending ? el('a', { href: `devices.html` }, String(team.pending)) : '0'),
        el('td', {}, String(team.disabled)))))));

    box.replaceChildren(
      el('div', { className: 'section-head' }, el('h2', {}, 'Обзор'), el('span', { className: 'muted' }, `обновлено ${formatDateTime(data.generated_at)}`),
        el('button', { type: 'button', className: 'button button--ghost', onclick: loadOverview }, 'Обновить')),
      el('div', { className: 'stat-grid' },
        stat('Устройств', d.total, `работают: ${d.by_status.ELIGIBLE ?? 0} · отключены: ${d.by_status.DISABLED ?? 0}`, 'devices.html'),
        stat('Ждут Apple', w.total, `дольше 3 дней: ${w.buckets['3_7d'] + w.buckets['7_14d'] + w.buckets.over_14d}`, 'devices.html'),
        stat('Клиентов с доступом', s.active, `всего с подпиской: ${s.customers}`, 'subscriptions.html'),
        stat('Кончается за 7 дней', s.ending_7d, `за 24 ч: ${s.ending_24h} · за 3 дня: ${s.ending_3d}`, 'subscriptions.html?state=ending7'),
        stat('Закончилась за 7 дней', s.ended_7d, `всего закончились: ${s.ended_total}`, 'subscriptions.html?state=expired7'),
        stat('Продажи сегодня', sales.today.orders, rub(sales.today.revenue_rub), 'store.html'),
        stat('Продажи за 7 дней', sales.last_7d.orders, rub(sales.last_7d.revenue_rub), 'store.html'),
        stat('Продажи за 30 дней', sales.last_30d.orders, rub(sales.last_30d.revenue_rub), 'sales.html'),
        stat('Заказы на проверке', sales.awaiting_review, sales.awaiting_review ? 'подтвердите в «Магазин»' : 'нет', 'store.html'),
        stat('Открытые обращения', data.tickets_open, '', 'users.html')),
      el('section', { className: 'card' }, el('h2', {}, 'Ожидание Apple'),
        el('p', {}, ...buckets.map(([key, label], i) => [i ? ' · ' : '', `${label}: `, el('strong', {}, String(w.buckets[key]))]).flat()),
        w.longest.length ? [el('h3', {}, 'Дольше всех ждут'), longest] : el('p', { className: 'muted' }, 'Никто не ждёт.')),
      el('section', { className: 'card' }, el('h2', {}, 'Команды Apple (текущий год)'), teams),
    );
  } catch (error) {
    box.replaceChildren(errorNotice(t, error, loadOverview));
  }
}

loadOverview();
loadHealth();
loadQuota();
loadStorage();
loadMetrics();
