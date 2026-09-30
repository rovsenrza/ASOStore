import { boot, renderDevice, renderError, renderLoading, renderStage } from '../app.js';
import { bindForm } from '../forms.js';

const { api, t } = boot();
const STAGE_TO_STEP = {
  signed_out: 0,
  email_verification_required: 0,
  activation_required: 0,
  device_required: 1,
  device_pending: 2,
  storefront_ready: 3,
  storefront_installed: 4,
};
const POLL_MIN_MS = 3000;
const POLL_MAX_MS = 30000;

const progressItems = [...document.querySelectorAll('.setup-progress li')];
const stepCount = document.querySelector('#step-count');
const stepTitle = document.querySelector('#step-title');
const stageState = document.querySelector('#stage-state');
const params = new URLSearchParams(location.search);
let pollDelay = POLL_MIN_MS;
let pollTimer = null;

const ua = navigator.userAgent;
const isIos = /iPhone|iPad/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isSafari = /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS|YaBrowser|Chrome|Android/.test(ua);

function renderProgress(step) {
  const total = progressItems.length;
  progressItems.forEach((item, index) => {
    item.classList.toggle('is-current', index === step);
    item.classList.toggle('is-complete', index < step);
    item.toggleAttribute('aria-current', index === step);
  });
  const current = Math.min(step, total - 1);
  stepCount.textContent = t('activate.stepCount', { current: current + 1, total });
  stepTitle.textContent = progressItems[current].querySelector('small').textContent;
}

function renderEnvironmentChecks() {
  const isIphone = /iPhone/.test(ua);
  const device = document.querySelector('#check-device');
  device.textContent = isIphone ? t('activate.checks.iphone') : isIos ? t('activate.checks.ipad') : t('activate.checks.other');
  device.className = isIos ? 'is-ok' : 'is-warn';

  const browser = document.querySelector('#check-browser');
  browser.textContent = isSafari ? t('activate.checks.safari') : t('activate.checks.otherBrowser');
  browser.className = isSafari ? 'is-ok' : 'is-warn';
}

function paragraph(text, className) {
  const node = document.createElement('p');
  if (className) node.className = className;
  node.textContent = text;
  return node;
}

/** One-off message after iOS sends the user back from the profile flow. */
function renderReturnNotice() {
  const error = params.get('enrollment_error');
  if (!error && !params.get('enrolled')) return null;

  const notice = document.createElement('div');
  notice.className = error ? 'notice notice--error' : 'notice notice--ok';
  notice.setAttribute('role', error ? 'alert' : 'status');
  notice.append(paragraph(error ? (t.has(`enrollmentErrors.${error}`) ? t(`enrollmentErrors.${error}`) : t('errors.INTERNAL')) : t('activate.enrolled')));
  return notice;
}

function renderActivationForm() {
  const form = document.createElement('form');
  form.className = 'form activation-form';
  form.noValidate = true;
  form.innerHTML = `
    <div class="form-status"></div>
    <div class="field">
      <label for="activation-code"></label>
      <input id="activation-code" name="code" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false"
        maxlength="32" aria-describedby="activation-code-hint" required>
      <p class="field-hint" id="activation-code-hint"></p>
    </div>
    <button class="primary-button" type="submit"><span></span> <span aria-hidden="true">→</span></button>`;
  form.querySelector('label').textContent = t('activate.codeLabel');
  form.querySelector('input').placeholder = t('activate.codePlaceholder');
  form.querySelector('.field-hint').textContent = t('activate.codeHint');
  form.querySelector('button span').textContent = t('activate.codeSubmit');

  bindForm(form, t, {
    submit: (data) => api.post('/activation/redeem', { code: data.get('code') }, {
      idempotencyKey: `redeem-${crypto.randomUUID?.() ?? Date.now()}`,
    }),
    onSuccess: load,
  });

  stageState.append(form);
}

/** Profile download on an iPhone/iPad in Safari; instructions everywhere else. */
function renderEnrollment() {
  const block = document.createElement('div');
  block.className = 'enrollment-steps';

  if (!isIos || !isSafari) {
    block.append(paragraph(t('activate.openOnIphone'), 'notice'));
    stageState.append(block);
    return;
  }

  const steps = document.createElement('ol');
  for (const step of t.list('activate.profileSteps')) {
    const item = document.createElement('li');
    item.textContent = step;
    steps.append(item);
  }
  const download = document.createElement('a');
  download.className = 'primary-button';
  download.href = '/api/v1/devices/enrollment-profile';
  download.textContent = t('activate.installProfile');

  block.append(steps, download, paragraph(t('activate.profileNote'), 'field-hint'));
  stageState.append(block);
}

function schedulePoll() {
  clearTimeout(pollTimer);
  pollTimer = setTimeout(() => {
    if (document.hidden) {
      schedulePoll();
      return;
    }
    load({ quiet: true });
  }, pollDelay);
  pollDelay = Math.min(pollDelay * 2, POLL_MAX_MS);
}

async function load({ quiet = false } = {}) {
  if (!quiet) renderLoading(stageState, t);
  try {
    const { data } = await api.get('/storefront/status');
    renderProgress(STAGE_TO_STEP[data.stage] ?? 0);
    renderStage(stageState, t, data.stage, {
      tone: data.stage === 'blocked' ? 'notice notice--error' : 'notice',
      next: location.pathname + location.search,
      reason: data.blocking_reason,
    });

    const returnNotice = renderReturnNotice();
    if (returnNotice) stageState.prepend(returnNotice);

    if (data.device && data.stage !== 'device_required') {
      stageState.append(renderDevice(t, data.device));
    }

    if (data.stage === 'activation_required') renderActivationForm();
    if (data.stage === 'device_required') renderEnrollment();
    if (data.stage === 'device_pending') {
      stageState.append(paragraph(t('activate.pendingPolling'), 'field-hint'));
      schedulePoll();
    } else {
      pollDelay = POLL_MIN_MS;
    }
  } catch (error) {
    if (quiet) {
      schedulePoll();
      return;
    }
    renderError(stageState, t, error, () => load());
  }
}

renderEnvironmentChecks();
load();
