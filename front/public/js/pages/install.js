import { boot, renderError, renderLoading, renderStage } from '../app.js';

const { api, t } = boot();
const state = document.querySelector('#install-state');
const installButton = document.querySelector('#install-storefront');
const openApp = document.querySelector('#open-storefront');

async function load() {
  renderLoading(state, t);
  try {
    const { data } = await api.get('/storefront/status');
    renderStage(state, t, data.stage, { reason: data.blocking_reason, next: location.pathname });

    const ready = data.stage === 'storefront_ready' || data.stage === 'storefront_installed';
    // The install handoff (authorize → itms-services link) arrives in Phase 6.
    installButton.setAttribute('aria-disabled', 'true');
    installButton.title = ready ? t('install.notYet') : t('install.unavailable');
    openApp.hidden = !ready;
  } catch (error) {
    renderError(state, t, error, load);
  }
}

installButton.addEventListener('click', (event) => {
  if (installButton.getAttribute('aria-disabled') === 'true') event.preventDefault();
});

openApp.querySelector('button').addEventListener('click', async (event) => {
  const button = event.currentTarget;
  button.disabled = true;
  try {
    const { data } = await api.post('/storefront/claims');
    location.assign(data.url);
  } catch (error) {
    renderError(openApp.querySelector('.form-status'), t, error);
  } finally {
    button.disabled = false;
  }
});

load();
