import { boot, redirectUnverified, renderError, renderLoading, renderStage } from '../app.js';

// Storefront installation (IMPLEMENTATION_PLAN P6-WEB-01): prepare → poll → authorize → itms-services.
const { api, t } = boot();
const state = document.querySelector('#install-state');
const installButton = document.querySelector('#install-storefront');
const progress = document.querySelector('#install-progress');
const openApp = document.querySelector('#open-storefront');
const STORAGE_KEY = 'storefront.installation';

// OTA installs only work in Safari on the device itself.
const onIphone = /iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

let pollTimer = null;

function remember(id) {
  try {
    id ? sessionStorage.setItem(STORAGE_KEY, id) : sessionStorage.removeItem(STORAGE_KEY);
  } catch { /* private mode: resuming after reload is a convenience only */ }
}

function remembered() {
  try { return sessionStorage.getItem(STORAGE_KEY); } catch { return null; }
}

function setButton(enabled, label = t('install.installAction')) {
  installButton.disabled = !enabled;
  installButton.setAttribute('aria-disabled', enabled ? 'false' : 'true');
  installButton.firstChild.textContent = `${label} `;
}

function message(title, text, tone = 'notice') {
  progress.hidden = false;
  progress.replaceChildren();
  const block = document.createElement('div');
  block.className = tone === 'notice' ? 'notice' : `notice notice--${tone}`;
  const heading = document.createElement('b');
  heading.textContent = title;
  const body = document.createElement('p');
  body.textContent = text;
  block.append(heading, body);
  progress.append(block);
  return block;
}

async function load() {
  renderLoading(state, t);
  try {
    const { data } = await api.get('/storefront/status');
    if (redirectUnverified({ stage: data.stage })) return;
    renderStage(state, t, data.stage, { reason: data.blocking_reason, next: location.pathname });

    const ready = data.stage === 'storefront_ready' || data.stage === 'storefront_installed';
    openApp.hidden = !ready;
    setButton(ready && onIphone);
    installButton.title = !ready ? t('install.unavailable') : (onIphone ? '' : t('install.notIphone'));
    if (ready && !onIphone) message(t('install.unavailable'), t('install.notIphone'));

    const resume = remembered();
    if (ready && resume) poll(resume, 0);
  } catch (error) {
    renderError(state, t, error, load);
  }
}

async function start() {
  setButton(false);
  message(t('install.preparingTitle'), t('install.preparing'));
  try {
    const { data } = await api.post('/storefront/install', {}, {
      idempotencyKey: `storefront-install-${crypto.randomUUID?.() ?? Date.now()}`,
    });
    remember(data.id);
    handle(data, 0);
  } catch (error) {
    setButton(true);
    renderError(progress, t, error);
  }
}

// Backoff 3 s → 30 s; the server keeps the state, so a reload resumes here.
function poll(id, attempt) {
  clearTimeout(pollTimer);
  pollTimer = setTimeout(async () => {
    try {
      const { data } = await api.get(`/installations/${id}`);
      handle(data, attempt + 1);
    } catch (error) {
      if (error.status === 404) { remember(null); setButton(true); return; }
      renderError(progress, t, error, () => poll(id, attempt));
    }
  }, Math.min(3000 * 2 ** Math.min(attempt, 4), 30000));
}

function handle(installation, attempt) {
  switch (installation.status) {
    case 'PREPARING': {
      const block = message(t('install.preparingTitle'), t('install.preparing'));
      const percent = installation.preparation?.progress;
      if (percent != null) {
        const bar = document.createElement('progress');
        bar.max = 1;
        bar.value = percent;
        bar.setAttribute('aria-label', t('install.progress', { percent: Math.round(percent * 100) }));
        block.append(bar);
      }
      poll(installation.id, attempt);
      break;
    }
    case 'READY_TO_INSTALL':
    case 'AUTHORIZED':
    case 'MANIFEST_FETCHED':
      message(t('install.readyTitle'), t('install.ready'), 'ok');
      setButton(onIphone);
      installButton.dataset.installation = installation.id;
      break;
    case 'DELIVERED':
      remember(null);
      message(t('install.openingTitle'), t('install.opening'), 'ok');
      setButton(onIphone);
      break;
    default: {
      remember(null);
      const block = message(t('install.failedTitle'), t('install.failed'), 'error');
      if (installation.status_reason) {
        const code = document.createElement('p');
        code.className = 'field-hint';
        code.textContent = installation.status_reason;
        block.append(code);
      }
      setButton(true, t('install.retry'));
    }
  }
}

async function authorize(id) {
  setButton(false);
  try {
    const { data } = await api.post(`/installations/${id}/authorize`, {}, {
      idempotencyKey: `storefront-authorize-${crypto.randomUUID?.() ?? Date.now()}`,
    });
    message(t('install.openingTitle'), t('install.opening'), 'ok');
    // iOS takes over from here: it fetches the manifest and asks the user to confirm.
    location.assign(data.install_url);
    setButton(true);
  } catch (error) {
    setButton(true);
    renderError(progress, t, error);
    if (error.code === 'INSTALL_TOKEN_EXPIRED' || error.code === 'ARTIFACT_NOT_INSTALLABLE') delete installButton.dataset.installation;
  }
}

installButton.addEventListener('click', (event) => {
  event.preventDefault();
  if (installButton.disabled) return;
  const ready = installButton.dataset.installation;
  ready ? authorize(ready) : start();
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
