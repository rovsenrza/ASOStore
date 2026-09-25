import { boot, el, errorNotice, formatDate, formatDateTime, newIdempotencyKey } from '../app.js';
import { confirmAction } from '../components/confirm-dialog.js';
import { createDataTable } from '../components/data-table.js';
import { openDialog } from '../components/dialog.js';
import { statusBadge } from '../components/status-badge.js';
import { toast } from '../components/toast.js';

const { api, t, can, me } = await boot();
const ROLES = ['customer', 'support', 'catalog_manager', 'admin'];

/* ---------- Tabs ---------- */

const tabs = [...document.querySelectorAll('[role="tab"]')];
const panels = tabs.map((tab) => document.getElementById(tab.getAttribute('aria-controls')));
const tabAllowed = { 'tab-users': can('users.view'), 'tab-codes': can('activation-codes.view') };

function selectTab(tab) {
  tabs.forEach((other, index) => {
    const selected = other === tab;
    other.setAttribute('aria-selected', String(selected));
    other.tabIndex = selected ? 0 : -1;
    panels[index].hidden = !selected;
  });
}

tabs.forEach((tab) => {
  tab.hidden = !tabAllowed[tab.id];
  tab.addEventListener('click', () => selectTab(tab));
});
selectTab(tabs.find((tab) => !tab.hidden));

/* ---------- Users ---------- */

const roleLabels = (roles) => roles.map((role) => t(`roles.${role}`)).join(', ') || t('common.none');

const usersTable = can('users.view') && createDataTable({
  container: document.querySelector('#users-table'),
  t,
  caption: t('codes.usersTab'),
  searchLabel: t('users.search'),
  filters: [
    { name: 'role', label: t('users.roles'), type: 'select', options: [['', t('users.allRoles')], ...ROLES.map((role) => [role, t(`roles.${role}`)])] },
    { name: 'status', label: t('users.status'), type: 'select', options: [['', t('users.allStatuses')], ['ACTIVE', t('userStatus.ACTIVE')], ['SUSPENDED', t('userStatus.SUSPENDED')]] },
  ],
  columns: [
    { key: 'name', label: t('users.name'), sortable: true },
    { key: 'email', label: t('users.email'), sortable: true },
    { key: 'roles', label: t('users.roles'), render: (user) => roleLabels(user.roles) },
    { key: 'status', label: t('users.status'), render: (user) => statusBadge(user.status, t(`userStatus.${user.status}`)) },
    { key: 'totp_enabled', label: t('users.totp'), render: (user) => (user.totp_enabled ? t('common.yes') : t('common.none')) },
    { key: 'subscription', label: t('users.subscription'), render: (user) => subscriptionText(user.subscription) },
    { key: 'last_login_at', label: t('users.lastLogin'), sortable: true, render: (user) => formatDateTime(user.last_login_at) },
    { key: 'actions', label: '', render: (user) => el('button', { type: 'button', className: 'button', onclick: () => openUser(user.id) }, t('common.open')) },
  ],
  fetchPage: async ({ page, perPage, query, filters, signal }) => {
    const params = new URLSearchParams({ page, per_page: perPage, ...filters });
    if (query) params.set('q', query);
    const { data, meta } = await api.get(`/admin/users?${params}`, { signal });
    return { rows: data, pagination: meta.pagination };
  },
});

function subscriptionText(subscription) {
  if (!subscription) return t('users.noSubscription');
  return subscription.ends_at ? formatDate(subscription.ends_at) : t('users.unlimited');
}

function definitionList(pairs) {
  return el('dl', { className: 'details' }, pairs.map(([term, value]) => el('div', {}, el('dt', {}, term), el('dd', {}, value))));
}

async function openUser(id) {
  const panel = openDialog(t, { title: t('common.loading'), body: el('p', {}, t('common.loading')), wide: true });

  async function render() {
    try {
      const { data: user } = await api.get(`/admin/users/${id}`);
      panel.dialog.querySelector('h2').textContent = user.name;
      panel.setBody(...userDetail(user, render));
    } catch (error) {
      panel.setBody(errorNotice(t, error, render));
    }
  }

  await render();
}

function userDetail(user, refresh) {
  const nodes = [
    definitionList([
      [t('users.email'), user.email],
      [t('users.status'), statusBadge(user.status, t(`userStatus.${user.status}`))],
      [t('users.roles'), roleLabels(user.roles)],
      [t('users.totp'), user.totp_enabled ? t('common.yes') : t('common.none')],
      [t('users.subscription'), subscriptionText(user.subscription)],
      [t('users.lastLogin'), formatDateTime(user.last_login_at)],
      [t('users.created'), formatDateTime(user.created_at)],
    ]),
  ];

  if (can('users.manage')) nodes.push(userActions(user, refresh));

  nodes.push(el('h3', {}, t('users.subscriptions')));
  nodes.push(user.subscriptions.length
    ? el('ul', { className: 'plain-list' }, user.subscriptions.map((sub) => el('li', {}, `${sub.plan} · ${formatDate(sub.starts_at)} — ${sub.ends_at ? formatDate(sub.ends_at) : t('users.unlimited')} · ${sub.status}`)))
    : el('p', { className: 'muted' }, t('users.noSubscription')));

  nodes.push(el('h3', {}, t('users.activity')));
  nodes.push(user.recent_activity.length
    ? el('ul', { className: 'plain-list' }, user.recent_activity.map((entry) => el('li', {},
      el('span', { className: 'muted' }, formatDateTime(entry.occurred_at)), ' ',
      el('span', { className: 'mono' }, entry.action),
      entry.reason ? ` — ${entry.reason}` : '')))
    : el('p', { className: 'muted' }, t('users.noActivity')));

  return nodes;
}

function userActions(user, refresh) {
  const isSelf = user.id === me.id;
  const roleBoxes = ROLES.map((role) => el('label', { className: 'check' },
    el('input', { type: 'checkbox', name: 'roles', value: role, checked: user.roles.includes(role), disabled: isSelf && role === 'admin' }),
    ` ${t(`roles.${role}`)}`));

  const saveRoles = el('button', { type: 'button', className: 'button button--primary' }, t('users.saveRoles'));
  saveRoles.addEventListener('click', async () => {
    const roles = roleBoxes.map((label) => label.querySelector('input')).filter((box) => box.checked).map((box) => box.value);
    const { confirmed, reason } = await confirmAction(t, { title: t('users.rolesTitle'), message: t('users.rolesMessage'), requireReason: true });
    if (!confirmed) return;
    await act(() => api.put(`/admin/users/${user.id}/roles`, { roles, reason }), t('users.rolesSaved'), refresh);
  });

  const suspended = user.status === 'SUSPENDED';
  const toggleStatus = el('button', { type: 'button', className: `button ${suspended ? '' : 'button--danger'}`, disabled: isSelf },
    suspended ? t('users.reactivate') : t('users.suspend'));
  toggleStatus.addEventListener('click', async () => {
    const { confirmed, reason } = await confirmAction(t, {
      title: suspended ? t('users.reactivateTitle') : t('users.suspendTitle'),
      message: suspended ? t('users.reactivateMessage') : t('users.suspendMessage'),
      danger: !suspended,
      requireReason: true,
    });
    if (!confirmed) return;
    await act(() => api.patch(`/admin/users/${user.id}`, { status: suspended ? 'ACTIVE' : 'SUSPENDED', reason }), t('users.statusSaved'), refresh);
  });

  const resetTotp = el('button', { type: 'button', className: 'button', disabled: !user.totp_enabled }, t('users.resetTotp'));
  resetTotp.addEventListener('click', async () => {
    const { confirmed, reason } = await confirmAction(t, { title: t('users.resetTotpTitle'), message: t('users.resetTotpMessage'), danger: true, requireReason: true });
    if (!confirmed) return;
    await act(() => api.post(`/admin/users/${user.id}/totp/reset`, { reason }), t('users.totpReset'), refresh);
  });

  return el('section', { className: 'card actions-card' },
    el('fieldset', { className: 'role-set' }, el('legend', {}, t('users.roles')), roleBoxes),
    el('div', { className: 'button-row' }, saveRoles, toggleStatus, resetTotp));
}

async function act(request, successMessage, refresh) {
  try {
    await request();
    toast(successMessage, { tone: 'ok' });
    await refresh();
    usersTable?.reload();
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
  }
}

const createButton = document.querySelector('#create-user');
createButton.hidden = !can('users.manage');
createButton.addEventListener('click', () => {
  const status = el('div');
  const form = el('form', { className: 'stack', noValidate: true },
    status,
    el('p', { className: 'muted' }, t('users.createHint')),
    el('label', { className: 'field' }, t('users.name'), el('input', { name: 'name', required: true, maxLength: 100, autocomplete: 'off' })),
    el('label', { className: 'field' }, t('users.email'), el('input', { name: 'email', type: 'email', required: true, autocomplete: 'off' })),
    el('fieldset', { className: 'role-set' }, el('legend', {}, t('users.roles')),
      ROLES.filter((role) => role !== 'customer').map((role) => el('label', { className: 'check' },
        el('input', { type: 'checkbox', name: 'roles', value: role, checked: role === 'support' }), ` ${t(`roles.${role}`)}`))),
    el('div', { className: 'button-row' }, el('button', { type: 'submit', className: 'button button--primary' }, t('users.create'))));

  const panel = openDialog(t, { title: t('users.create'), body: form });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    const data = new FormData(form);
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      await api.post('/admin/users', { name: data.get('name'), email: data.get('email'), roles: data.getAll('roles') });
      panel.close();
      toast(t('users.created_toast'), { tone: 'ok' });
      usersTable?.reload();
    } catch (error) {
      status.replaceChildren(errorNotice(t, error));
      button.disabled = false;
    }
  });
});

/* ---------- Activation codes ---------- */

const codesTable = can('activation-codes.view') && createDataTable({
  container: document.querySelector('#codes-table'),
  t,
  caption: t('codes.tab'),
  searchLabel: t('codes.code'),
  filters: [
    { name: 'status', label: t('users.status'), type: 'select', options: [['', t('codes.allStatuses')], ...['ISSUED', 'REDEEMED', 'EXPIRED', 'REVOKED'].map((s) => [s, t(`codeStatus.${s}`)])] },
  ],
  columns: [
    { key: 'hint', label: t('codes.code'), className: 'mono', render: (code) => `••••-${code.hint}` },
    { key: 'status', label: t('users.status'), render: (code) => statusBadge(code.status, t(`codeStatus.${code.status}`)) },
    { key: 'plan', label: t('codes.plan') },
    { key: 'duration_days', label: t('codes.duration'), render: (code) => (code.duration_days ? t('codes.days', { count: code.duration_days }) : t('users.unlimited')) },
    { key: 'expires_at', label: t('codes.expires'), render: (code) => formatDate(code.expires_at) },
    { key: 'redeemed_by', label: t('codes.redeemedBy'), render: (code) => (code.redeemed_by ? `${code.redeemed_by.email} · ${formatDate(code.redeemed_at)}` : t('common.none')) },
    { key: 'created_at', label: t('codes.created'), sortable: true, render: (code) => `${formatDate(code.created_at)} · ${code.created_by.email}` },
    { key: 'note', label: t('codes.note') },
    {
      key: 'actions',
      label: '',
      render: (code) => (can('activation-codes.manage') && code.status === 'ISSUED'
        ? el('button', { type: 'button', className: 'button button--danger', onclick: () => revokeCode(code) }, t('codes.revoke'))
        : ''),
    },
  ],
  fetchPage: async ({ page, perPage, query, filters, signal }) => {
    const params = new URLSearchParams({ page, per_page: perPage, ...filters });
    if (query) params.set('q', query.replace(/[^0-9A-Za-z]/g, '').slice(-4));
    const { data, meta } = await api.get(`/admin/activation-codes?${params}`, { signal });
    return { rows: data, pagination: meta.pagination };
  },
});

async function revokeCode(code) {
  const { confirmed, reason } = await confirmAction(t, { title: t('codes.revokeTitle'), message: t('codes.revokeMessage'), danger: true, requireReason: true });
  if (!confirmed) return;
  try {
    await api.post(`/admin/activation-codes/${code.id}/revoke`, { reason });
    toast(t('codes.revoked'), { tone: 'ok' });
    codesTable.reload();
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
  }
}

const generateForm = document.querySelector('#generate-form');
const generateResult = document.querySelector('#generate-result');
generateForm.hidden = !can('activation-codes.manage');
// One key per intended batch: a retried submit cannot create a second batch.
let batchKey = newIdempotencyKey('batch');

generateForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!generateForm.reportValidity()) return;
  const data = new FormData(generateForm);
  const button = generateForm.querySelector('[type="submit"]');
  const body = { count: Number(data.get('count')) };
  if (data.get('duration_days')) body.duration_days = Number(data.get('duration_days'));
  if (data.get('expires_at')) body.expires_at = new Date(data.get('expires_at')).toISOString();
  if (data.get('note')) body.note = data.get('note');

  button.disabled = true;
  generateResult.replaceChildren();
  try {
    const { data: batch } = await api.post('/admin/activation-codes', body, { idempotencyKey: batchKey });
    batchKey = newIdempotencyKey('batch');
    generateForm.reset();
    showBatch(batch);
    codesTable.reload();
  } catch (error) {
    generateResult.replaceChildren(errorNotice(t, error));
  } finally {
    button.disabled = false;
  }
});

function showBatch(batch) {
  const copy = el('button', { type: 'button', className: 'button' }, t('codes.copy'));
  copy.addEventListener('click', async () => {
    await navigator.clipboard.writeText(batch.codes.join('\n'));
    toast(t('codes.copied'), { tone: 'ok' });
  });

  const csv = `code,batch_id\n${batch.codes.map((code) => `${code},${batch.batch_id}`).join('\n')}\n`;
  const download = el('a', {
    className: 'button',
    href: URL.createObjectURL(new Blob([csv], { type: 'text/csv' })),
    download: `activation-codes-${batch.batch_id}.csv`,
  }, t('codes.download'));

  generateResult.replaceChildren(el('section', { className: 'card batch-result', role: 'status' },
    el('h3', {}, `${t('codes.resultTitle')}: ${batch.count}`),
    el('p', { className: 'notice' }, t('codes.resultWarning')),
    el('ol', { className: 'code-list mono' }, batch.codes.map((code) => el('li', {}, code))),
    el('div', { className: 'button-row' }, copy, download)));
}
