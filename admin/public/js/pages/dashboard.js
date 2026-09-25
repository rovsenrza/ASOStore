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

loadHealth();
loadQuota();
