import { boot } from '../app.js';
import { statusBadge } from '../components/status-badge.js';

const { api, t } = await boot();
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

loadHealth();
