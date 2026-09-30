import { boot, renderDevice, renderError, renderLoading, renderStage } from '../app.js';

const { api, t } = boot();
const profile = document.querySelector('#profile');
const state = document.querySelector('#account-state');
const dateFormat = new Intl.DateTimeFormat('ru-RU', { dateStyle: 'long' });

function row(list, label, value) {
  const item = document.createElement('div');
  const term = document.createElement('dt');
  term.textContent = label;
  const detail = document.createElement('dd');
  detail.textContent = value;
  item.append(term, detail);
  list.append(item);
}

function renderProfile(user) {
  const list = document.createElement('dl');
  list.className = 'profile-list';
  row(list, t('account.name'), user.name);
  row(list, t('account.email'), user.email);
  if (user.subscription) {
    row(list, t('account.plan'), user.subscription.plan);
    row(list, t('account.validUntil'), user.subscription.ends_at ? dateFormat.format(new Date(user.subscription.ends_at)) : t('account.noEnd'));
  } else {
    row(list, t('account.plan'), t('account.noSubscription'));
  }

  const signOut = document.createElement('button');
  signOut.type = 'button';
  signOut.className = 'secondary-button';
  signOut.textContent = t('account.signOut');
  signOut.addEventListener('click', async () => {
    signOut.disabled = true;
    try {
      await api.post('/auth/logout');
      location.assign('/');
    } catch (error) {
      signOut.disabled = false;
      renderError(profile, t, error);
    }
  });

  profile.replaceChildren(list, signOut);
}

async function load() {
  renderLoading(profile, t);
  renderLoading(state, t);
  try {
    const [{ data: user }, { data: status }] = await Promise.all([api.get('/auth/me'), api.get('/storefront/status')]);
    renderProfile(user);
    document.querySelector('#data-panel').hidden = false;
    renderStage(state, t, status.stage, { reason: status.blocking_reason });
    if (status.device) state.append(renderDevice(t, status.device));
  } catch (error) {
    if (error.code === 'UNAUTHENTICATED') {
      profile.replaceChildren();
      renderStage(profile, t, 'signed_out', { next: location.pathname });
      state.replaceChildren();
      return;
    }
    renderError(profile, t, error, load);
    state.replaceChildren();
  }
}

load();

// Data export and deletion request (IMPLEMENTATION_PLAN P8-SEC-02).
const dataStatus = document.querySelector('#data-status');

document.querySelector('#export-data').addEventListener('click', async (event) => {
  const button = event.currentTarget;
  button.disabled = true;
  try {
    const { data } = await api.get('/account/export');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
    link.download = 'account-data.json';
    link.click();
    URL.revokeObjectURL(link.href);
    dataStatus.textContent = t('data.exported');
  } catch (error) {
    renderError(dataStatus, t, error);
  } finally {
    button.disabled = false;
  }
});

document.querySelector('#delete-account').addEventListener('click', async (event) => {
  if (!window.confirm(t('data.deleteConfirm'))) return;
  const button = event.currentTarget;
  button.disabled = true;
  try {
    await api.post('/account/deletion-request');
    dataStatus.textContent = t('data.deleteRequested');
  } catch (error) {
    button.disabled = false;
    renderError(dataStatus, t, error);
  }
});
