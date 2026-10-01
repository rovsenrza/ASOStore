import { boot, redirectUnverified, renderError, renderLoading, renderStage } from '../app.js';

// Opened by the iOS app in a system web sign-in sheet (it shares Safari's cookies): the portal session
// becomes a one-time storefront://claim link, which the sheet hands back to the app.
const { api, t } = boot();
const status = document.querySelector('#app-signin-status');
const SELF = '/app-signin.html';

async function handOff() {
  renderLoading(status, t);
  try {
    const { data: current } = await api.get('/storefront/status');
    if (redirectUnverified({ stage: current.stage })) return;
    if (current.stage === 'signed_out') {
      location.replace(`/login.html?next=${encodeURIComponent(SELF)}`);
      return;
    }
    if (current.stage !== 'storefront_ready' && current.stage !== 'storefront_installed') {
      renderStage(status, t, current.stage, { reason: current.blocking_reason, next: SELF });
      return;
    }
    const { data } = await api.post('/storefront/claims');
    location.replace(data.url);
  } catch (error) {
    renderError(status, t, error, handOff);
  }
}

handOff();
