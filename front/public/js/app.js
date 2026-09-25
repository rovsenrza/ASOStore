import { createRuntime } from '/shared/js/runtime.js';
import { createTranslator } from '/shared/js/i18n.js';
import ru from './i18n/ru.js';

/**
 * Shared page shell: API client (live or mock), translator, header state.
 */
export function boot() {
  const { api, mock } = createRuntime();
  const t = createTranslator(ru);

  document.querySelectorAll('[data-year]').forEach((node) => { node.textContent = new Date().getFullYear(); });

  const path = location.pathname.replace(/\/$/, '/index.html');
  document.querySelectorAll('.site-header nav a').forEach((link) => {
    if (new URL(link.href).pathname === path) link.setAttribute('aria-current', 'page');
  });

  if (mock) {
    const badge = document.createElement('p');
    badge.className = 'demo-badge';
    badge.textContent = t('common.demoData');
    document.body.append(badge);
  }

  return { api, t, mock };
}

/**
 * Renders an error notice with a retry button and the request ID for support.
 */
export function renderError(container, t, error, onRetry) {
  container.replaceChildren();
  const notice = document.createElement('div');
  notice.className = 'notice notice--error';
  notice.setAttribute('role', 'alert');

  const text = document.createElement('p');
  text.textContent = t.error(error);
  notice.append(text);

  if (error?.requestId) {
    const id = document.createElement('p');
    id.className = 'request-id';
    id.textContent = t('common.requestId', { id: error.requestId });
    notice.append(id);
  }

  if (onRetry) {
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'secondary-button';
    retry.textContent = t('common.retry');
    retry.addEventListener('click', onRetry);
    notice.append(retry);
  }

  container.append(notice);
}

export function renderLoading(container, t) {
  container.replaceChildren();
  const line = document.createElement('p');
  line.className = 'loading-line';
  line.innerHTML = '<span class="status-pulse" aria-hidden="true"></span>';
  line.append(t('common.loading'));
  container.append(line);
}

/**
 * Title, text and optional action link for a /storefront/status stage.
 * With `next`, a sign-in link returns the user to that path afterwards.
 */
export function renderStage(container, t, stage, { tone = 'notice', next = null, reason = null } = {}) {
  container.replaceChildren();
  const block = document.createElement('div');
  block.className = tone;

  const title = document.createElement('b');
  title.textContent = t(`status.${stage}.title`);
  const text = document.createElement('p');
  // A blocking reason explains the stage more precisely than the generic text.
  text.textContent = reason && t.has(`blocking.${reason}`) ? t(`blocking.${reason}`) : t(`status.${stage}.text`);
  block.append(title, text);

  if (t.has(`status.${stage}.action`)) {
    const link = document.createElement('a');
    const href = t(`status.${stage}.href`);
    link.href = next && href === '/login.html' ? `${href}?next=${encodeURIComponent(next)}` : href;
    link.textContent = t(`status.${stage}.action`);
    link.className = 'text-link';
    block.append(link);
  }

  container.append(block);
}

const dateFormat = new Intl.DateTimeFormat('ru-RU', { dateStyle: 'long' });

/**
 * Device card from a /storefront/status or /devices/me device summary.
 */
export function renderDevice(t, device) {
  const list = document.createElement('dl');
  list.className = 'profile-list';
  const status = device.registration ? device.registration.status : 'none';
  const rows = [
    [t('device.model'), `${t(`device.family.${device.family}`)}${device.product ? ` (${device.product})` : ''}`],
    [t('device.identifier'), device.udid_hint],
    [t('device.ios'), device.os_version ?? '—'],
    [t('device.enrolledAt'), device.enrolled_at ? dateFormat.format(new Date(device.enrolled_at)) : '—'],
    [t('device.registration'), t(`device.status.${status}`)],
  ];
  for (const [label, value] of rows) {
    const item = document.createElement('div');
    const term = document.createElement('dt');
    term.textContent = label;
    const detail = document.createElement('dd');
    detail.textContent = value;
    item.append(term, detail);
    list.append(item);
  }
  return list;
}
