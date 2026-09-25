import { boot, el, errorNotice, formatDateTime } from '../app.js';
import { confirmAction } from '../components/confirm-dialog.js';
import { createDataTable } from '../components/data-table.js';
import { openDialog } from '../components/dialog.js';
import { statusBadge } from '../components/status-badge.js';
import { toast } from '../components/toast.js';

const { api, t, can } = await boot();
const STATUSES = ['ENROLLED', 'APPLE_PENDING', 'ELIGIBLE', 'APPLE_FAILED', 'QUOTA_BLOCKED', 'NO_ELIGIBLE_TEAM', 'DISABLED'];

function registrationBadge(registration) {
  if (!registration) return statusBadge('', t('devices.notConnected'));
  return statusBadge(registration.status, t(`devices.status.${registration.status}`));
}

const table = createDataTable({
  container: document.querySelector('#devices-table'),
  t,
  caption: t('devices.registration'),
  searchLabel: t('devices.search'),
  filters: [
    { name: 'status', label: t('devices.registration'), type: 'select', options: [['', t('devices.allStatuses')], ...STATUSES.map((s) => [s, t(`devices.status.${s}`)])] },
    { name: 'family', label: t('devices.family'), type: 'select', options: [['', t('devices.allFamilies')], ['IPHONE', 'iPhone'], ['IPAD', 'iPad']] },
  ],
  columns: [
    { key: 'udid_hint', label: t('devices.identifier'), className: 'mono' },
    { key: 'owner', label: t('devices.owner'), render: (device) => device.user.email },
    { key: 'product', label: t('devices.model'), className: 'mono' },
    { key: 'os_version', label: t('devices.ios') },
    { key: 'registration', label: t('devices.registration'), render: (device) => registrationBadge(device.registration) },
    { key: 'reason', label: t('devices.reason'), className: 'mono', render: (device) => device.registration?.reason ?? '' },
    { key: 'enrolled_at', label: t('devices.enrolled'), sortable: true, render: (device) => formatDateTime(device.enrolled_at) },
    { key: 'actions', label: '', render: (device) => el('button', { type: 'button', className: 'button', onclick: () => openDevice(device.id) }, t('common.open')) },
  ],
  fetchPage: async ({ page, perPage, query, filters, signal }) => {
    const params = new URLSearchParams({ page, per_page: perPage, ...filters });
    if (query) params.set('q', query);
    const { data, meta } = await api.get(`/admin/devices?${params}`, { signal });
    return { rows: data, pagination: meta.pagination };
  },
});

async function openDevice(id) {
  const panel = openDialog(t, { title: t('common.loading'), body: el('p', {}, t('common.loading')), wide: true });

  async function render() {
    try {
      const { data: device } = await api.get(`/admin/devices/${id}`);
      panel.dialog.querySelector('h2').textContent = `${device.udid_hint} · ${device.user.email}`;
      panel.setBody(...detail(device, panel));
    } catch (error) {
      panel.setBody(errorNotice(t, error, render));
    }
  }

  await render();
}

function detail(device, panel) {
  const nodes = [
    el('dl', { className: 'details' },
      [[t('devices.owner'), device.user.email], [t('devices.model'), device.product ?? '—'], [t('devices.ios'), device.os_version ?? '—'],
        [t('devices.registration'), registrationBadge(device.registration)], [t('devices.enrolled'), formatDateTime(device.enrolled_at)],
        [t('devices.claimed'), formatDateTime(device.storefront_claimed_at)]]
        .map(([term, value]) => el('div', {}, el('dt', {}, term), el('dd', {}, value)))),
  ];

  const actions = el('div', { className: 'button-row' });
  if (can('devices.manage')) {
    const sync = el('button', { type: 'button', className: 'button' }, t('devices.sync'));
    sync.addEventListener('click', async () => {
      sync.disabled = true;
      try {
        await api.post(`/admin/devices/${device.id}/sync`);
        toast(t('devices.synced'), { tone: 'ok' });
        table.reload();
      } catch (error) {
        toast(t.error(error), { tone: 'error' });
      } finally {
        sync.disabled = false;
      }
    });
    actions.append(sync);
  }
  if (can('devices.reveal-udid')) {
    const reveal = el('button', { type: 'button', className: 'button button--danger' }, t('devices.reveal'));
    const output = el('code', { className: 'mono revealed-udid' });
    reveal.addEventListener('click', async () => {
      const { confirmed, reason } = await confirmAction(t, { title: t('devices.revealTitle'), message: t('devices.revealMessage'), danger: true, requireReason: true });
      if (!confirmed) return;
      try {
        const { data } = await api.post(`/admin/devices/${device.id}/reveal-udid`, { reason });
        output.textContent = data.udid;
        reveal.replaceWith(output);
      } catch (error) {
        toast(t.error(error), { tone: 'error' });
      }
    });
    actions.append(reveal);
  }
  if (actions.childElementCount) nodes.push(actions);

  nodes.push(el('h3', {}, t('devices.registrations')));
  nodes.push(device.registrations.length
    ? el('ul', { className: 'plain-list' }, device.registrations.map((registration) => el('li', {},
      statusBadge(registration.status, t(`devices.status.${registration.status}`)), ' ',
      el('span', { className: 'mono' }, `${t('devices.team')} ${registration.team}`),
      registration.apple_device_id ? ` · ${t('devices.appleId')} ${registration.apple_device_id}` : '',
      ` · ${t('devices.attempts')}: ${registration.attempts}`,
      registration.reason ? el('span', { className: 'mono' }, ` · ${registration.reason}`) : '')))
    : el('p', { className: 'muted' }, t('devices.notConnected')));

  nodes.push(el('h3', {}, t('devices.history')));
  nodes.push(el('ul', { className: 'plain-list' }, device.history.map((entry) => el('li', {},
    el('span', { className: 'muted' }, formatDateTime(entry.occurred_at)), ' ',
    el('span', { className: 'mono' }, entry.action),
    entry.after?.status ? ` → ${entry.after.status}` : '',
    entry.reason ? ` — ${entry.reason}` : ''))));

  return nodes;
}
