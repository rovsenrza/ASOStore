import { boot } from '../app.js';
import { statusBadge } from '../components/status-badge.js';

const { api, t, can } = await boot();
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

loadHealth();
loadQuota();
loadStorage();
loadMetrics();
