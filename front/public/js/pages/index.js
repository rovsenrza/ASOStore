import { boot, renderError, renderLoading, renderStage } from '../app.js';

const { api, t } = boot();
const button = document.querySelector('#status-check');
const result = document.querySelector('#status-result');

async function checkStatus() {
  button.disabled = true;
  renderLoading(result, t);
  try {
    const { data } = await api.get('/storefront/status');
    renderStage(result, t, data.stage, { tone: 'status-stage' });
  } catch (error) {
    renderError(result, t, error, checkStatus);
  } finally {
    button.disabled = false;
  }
}

button.addEventListener('click', checkStatus);
