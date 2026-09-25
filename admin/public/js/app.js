import { createRuntime } from '/shared/js/runtime.js';
import { createTranslator } from '/shared/js/i18n.js';
import ru from './i18n/ru.js';

/**
 * Pages whose nav link needs a permission (IMPLEMENTATION_PLAN §5.9). The
 * server enforces every permission; this only hides what cannot be used.
 */
const NAV_PERMISSIONS = {
  'users.html': ['users.view', 'activation-codes.view'],
  'audit.html': ['audit.view'],
  'devices.html': ['devices.view'],
  'apps.html': ['catalog.view'],
};

const SIGN_IN_CODES = new Set(['UNAUTHENTICATED', 'TOTP_REQUIRED', 'SESSION_EXPIRED']);

/**
 * Admin page shell. Resolves once the operator is known; pages that need no
 * session (the login page) pass requireAuth: false.
 */
export async function boot({ requireAuth = true } = {}) {
  const { api, mock } = createRuntime();
  const t = createTranslator(ru);

  const page = location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.sidebar nav a').forEach((link) => {
    if (link.getAttribute('href') === page) link.setAttribute('aria-current', 'page');
  });

  if (mock) {
    const badge = document.createElement('span');
    badge.className = 'mock-badge';
    badge.textContent = t('common.mockData');
    (document.querySelector('.topbar') ?? document.body).append(badge);
  }

  if (!requireAuth) return { api, t, mock, me: null, can: () => false };

  try {
    const { data: me } = await api.get('/admin/auth/me');
    const can = (permission) => me.permissions.includes(permission);
    renderOperator(api, t, me);
    document.querySelectorAll('.sidebar nav a').forEach((link) => {
      const needed = NAV_PERMISSIONS[link.getAttribute('href')];
      if (needed && !needed.some(can)) link.hidden = true;
    });
    return { api, t, mock, me, can };
  } catch (error) {
    if (SIGN_IN_CODES.has(error?.code)) {
      location.replace(`login.html?next=${encodeURIComponent(location.pathname + location.search)}`);
    } else {
      document.querySelector('.content')?.replaceChildren(errorNotice(t, error, () => location.reload()));
    }
    // Stop the page script: nothing below should run without an operator.
    return new Promise(() => {});
  }
}

function renderOperator(api, t, me) {
  const foot = document.querySelector('.sidebar-foot');
  if (!foot) return;

  const name = document.createElement('strong');
  name.textContent = me.name;
  const details = document.createElement('span');
  details.textContent = `${me.email} · ${me.roles.map((role) => t(`roles.${role}`)).join(', ')}`;
  const signOut = document.createElement('button');
  signOut.type = 'button';
  signOut.className = 'button button--ghost';
  signOut.textContent = t('common.signOut');
  signOut.addEventListener('click', async () => {
    signOut.disabled = true;
    try {
      await api.post('/admin/auth/logout');
    } finally {
      location.assign('login.html');
    }
  });

  foot.replaceChildren(name, details, signOut);
  foot.classList.add('operator');
}

export function errorNotice(t, error, onRetry) {
  const notice = document.createElement('div');
  notice.className = 'notice notice--error';
  notice.setAttribute('role', 'alert');

  const text = document.createElement('p');
  text.textContent = t.error(error);
  notice.append(text);

  const fields = Object.values(error?.details?.fields ?? {}).flat();
  if (fields.length) {
    const list = document.createElement('ul');
    for (const message of fields) {
      const item = document.createElement('li');
      item.textContent = message;
      list.append(item);
    }
    notice.append(list);
  }

  if (error?.requestId) {
    const id = document.createElement('p');
    id.className = 'request-id mono';
    id.textContent = t('common.requestId', { id: error.requestId });
    notice.append(id);
  }

  if (onRetry) {
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'button';
    retry.textContent = t('common.retry');
    retry.addEventListener('click', onRetry);
    notice.append(retry);
  }

  return notice;
}

const dateTime = new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' });
const date = new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' });

export function formatDateTime(iso) {
  return iso ? dateTime.format(new Date(iso)) : '—';
}

export function formatDate(iso) {
  return iso ? date.format(new Date(iso)) : '—';
}

/**
 * Builds an element: el('p', { className: 'x' }, 'text', otherNode).
 */
export function el(tag, props = {}, ...children) {
  const node = Object.assign(document.createElement(tag), props);
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue;
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}

export function newIdempotencyKey(prefix) {
  return `${prefix}-${globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`}`;
}

/**
 * Tabs with role="tab" / aria-controls. Hides tabs the operator cannot use.
 */
export function setupTabs(allowed = () => true) {
  const tabs = [...document.querySelectorAll('[role="tab"]')];
  const panels = tabs.map((tab) => document.getElementById(tab.getAttribute('aria-controls')));

  function select(tab) {
    tabs.forEach((other, index) => {
      const selected = other === tab;
      other.setAttribute('aria-selected', String(selected));
      other.tabIndex = selected ? 0 : -1;
      panels[index].hidden = !selected;
    });
  }

  tabs.forEach((tab) => {
    tab.hidden = !allowed(tab.id);
    tab.addEventListener('click', () => select(tab));
  });
  select(tabs.find((tab) => !tab.hidden));
}

/**
 * Shrinks large images in the browser before upload (hosting limits are often 2 MB).
 */
export async function prepareImage(file, { maxBytes = 1_900_000, maxWidth = 2048 } = {}) {
  if (file.size <= maxBytes) return file;
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, maxWidth / bitmap.width);
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
  return new File([blob], file.name.replace(/\.\w+$/, '.jpg'), { type: 'image/jpeg' });
}
