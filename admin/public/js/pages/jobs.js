import { boot, el, errorNotice, formatDate, formatDateTime, newIdempotencyKey, setupTabs } from '../app.js';
import { confirmAction } from '../components/confirm-dialog.js';
import { createDataTable } from '../components/data-table.js';
import { openDialog } from '../components/dialog.js';
import { statusBadge } from '../components/status-badge.js';
import { toast } from '../components/toast.js';

// Pipeline jobs, signing runner health and installation timelines (P5-ADM-02, P6-ADM-01).
const { api, t, can } = await boot();
const JOB_STATUSES = ['QUEUED', 'LEASED', 'RUNNING', 'SUCCEEDED', 'FAILED_RETRYABLE', 'FAILED_PERMANENT', 'CANCELLED'];
const JOB_TYPES = ['InspectArtifactJob', 'PrepareSigningJob', 'SignArtifactJob', 'VerifySignatureJob'];
const INSTALLATION_STATUSES = ['PREPARING', 'READY_TO_INSTALL', 'AUTHORIZED', 'MANIFEST_FETCHED', 'DELIVERED', 'FAILED', 'EXPIRED'];

setupTabs((id) => id !== 'tab-installations' || can('installations.view'));

const typeLabel = (type) => (t.has(`jobs.types.${type}`) ? t(`jobs.types.${type}`) : type);
const jobBadge = (status) => statusBadge(status, t(`jobs.status.${status}`));

// Runners -------------------------------------------------------------------
const runnersBox = document.querySelector('#runners');

async function loadRunners() {
  try {
    const { data, meta } = await api.get('/admin/runners');
    runnersBox.replaceChildren(
      el('div', { className: 'card' }, el('h2', {}, t('runners.queued', { count: meta.queued_signing_jobs ?? 0 }))),
      ...(data.length ? data.map(runnerCard) : [el('p', { className: 'muted' }, t('runners.none'))]),
    );
  } catch (error) {
    runnersBox.replaceChildren(errorNotice(t, error, loadRunners));
  }
}

function runnerCard(runner) {
  const state = runner.status !== 'ACTIVE' ? ['DISABLED', t('runners.disabled')]
    : runner.online ? ['ACTIVE', t('runners.online')] : ['FAILED', t('runners.offline')];

  return el('article', { className: 'card' },
    el('h2', {}, runner.name, ' ', statusBadge(...state)),
    el('p', { className: 'muted' }, runner.last_heartbeat_at ? t('runners.lastHeartbeat', { time: formatDateTime(runner.last_heartbeat_at) }) : t('runners.never')),
    el('p', { className: 'mono' }, `${runner.key_id}${runner.version ? ` · v${runner.version}` : ''}`),
    el('p', {}, t('runners.busy', { count: runner.current_jobs.length })),
    el('p', {}, t('runners.identities', { count: runner.identities.length })),
    el('ul', { className: 'plain-list' }, runner.identities.map((identity) => el('li', { className: 'mono' },
      `${identity.team_identifier} · ${identity.sha1.slice(0, 8)}… ${identity.expires_at ? t('runners.expires', { date: formatDate(identity.expires_at) }) : ''}`))),
  );
}

// Jobs ----------------------------------------------------------------------
const jobs = createDataTable({
  container: document.querySelector('#jobs-table'),
  t,
  caption: t('jobs.type'),
  searchable: false,
  filters: [
    { name: 'status[]', label: t('jobs.statusLabel'), type: 'select', options: [['', t('jobs.allStatuses')], ...JOB_STATUSES.map((s) => [s, t(`jobs.status.${s}`)])] },
    { name: 'type', label: t('jobs.type'), type: 'select', options: [['', t('jobs.allTypes')], ...JOB_TYPES.map((type) => [type, typeLabel(type)])] },
  ],
  columns: [
    { key: 'type', label: t('jobs.type'), render: (job) => typeLabel(job.type) },
    { key: 'status', label: t('jobs.statusLabel'), render: (job) => jobBadge(job.status) },
    { key: 'attempt', label: t('jobs.attempt'), render: (job) => `${job.attempt} / ${job.max_attempts}` },
    { key: 'result', label: t('jobs.result'), className: 'mono', render: (job) => job.result_code ?? job.error_class?.split('\\').pop() ?? '' },
    { key: 'created_at', label: t('jobs.created'), sortable: true, render: (job) => formatDateTime(job.created_at) },
    { key: 'actions', label: '', render: (job) => el('button', { type: 'button', className: 'button', onclick: () => openJob(job.id) }, t('common.open')) },
  ],
  fetchPage: async ({ page, perPage, filters, signal }) => {
    const params = new URLSearchParams({ page, per_page: perPage });
    Object.entries(filters).forEach(([name, value]) => value && params.set(name, value));
    const { data, meta } = await api.get(`/admin/jobs?${params}`, { signal });
    return { rows: data, pagination: meta.pagination };
  },
});

async function openJob(id) {
  const panel = openDialog(t, { title: t('common.loading'), body: el('p', {}, t('common.loading')), wide: true });

  async function render() {
    try {
      const { data: job } = await api.get(`/admin/jobs/${id}`);
      panel.dialog.querySelector('h2').textContent = `${typeLabel(job.type)} · ${job.id}`;
      panel.setBody(...jobDetail(job, render));
    } catch (error) {
      panel.setBody(errorNotice(t, error, render));
    }
  }

  await render();
}

function jobDetail(job, rerender) {
  const nodes = [
    el('dl', { className: 'details' },
      [[t('jobs.statusLabel'), jobBadge(job.status)], [t('jobs.attempt'), `${job.attempt} / ${job.max_attempts}`],
        [t('jobs.subject'), job.subject ? `${job.subject.type} ${job.subject.id}` : '—'], ['correlation_id', job.correlation_id],
        [t('jobs.result'), job.result_code ?? '—'], [t('jobs.error'), job.error_message ?? '—'],
        [t('jobs.started'), formatDateTime(job.started_at)], [t('jobs.finished'), formatDateTime(job.finished_at)]]
        .map(([term, value]) => el('div', {}, el('dt', {}, term), el('dd', { className: typeof value === 'string' ? 'mono' : '' }, value)))),
  ];

  if (can('jobs.manage') && ['FAILED_PERMANENT', 'FAILED_RETRYABLE'].includes(job.status)) {
    const retry = el('button', { type: 'button', className: 'button button--primary' }, t('jobs.retry'));
    retry.addEventListener('click', async () => {
      const { confirmed, reason } = await confirmAction(t, { title: t('jobs.retryTitle'), message: t('jobs.retryMessage'), requireReason: true });
      if (!confirmed) return;
      try {
        await api.post(`/admin/jobs/${job.id}/retry`, { reason }, { idempotencyKey: newIdempotencyKey('job-retry') });
        toast(t('jobs.retried'), { tone: 'ok' });
        jobs.reload();
        rerender();
      } catch (error) {
        toast(t.error(error), { tone: 'error' });
      }
    });
    nodes.push(el('div', { className: 'button-row' }, retry));
  }

  nodes.push(el('h3', {}, t('jobs.attempts')));
  nodes.push(el('ul', { className: 'plain-list' }, job.attempts.map((attempt) => el('li', {},
    el('span', { className: 'muted' }, `#${attempt.attempt} ${formatDateTime(attempt.started_at)}`), ' ',
    el('span', { className: 'mono' }, attempt.worker ?? ''), ' ',
    attempt.result_code ? el('span', { className: 'mono' }, attempt.result_code) : '',
    attempt.error_message ? ` — ${attempt.error_message}` : ''))));

  return nodes;
}

// Installations -------------------------------------------------------------
if (can('installations.view')) {
  createDataTable({
    container: document.querySelector('#installations-table'),
    t,
    caption: t('installations.status'),
    searchLabel: t('installations.search'),
    filters: [
      { name: 'status', label: t('installations.status'), type: 'select', options: [['', t('jobs.allStatuses')], ...INSTALLATION_STATUSES.map((s) => [s, t(`installations.statuses.${s}`)])] },
    ],
    columns: [
      { key: 'app', label: t('installations.app'), render: (row) => row.app.name },
      { key: 'user', label: t('installations.user'), render: (row) => row.user.email },
      { key: 'device', label: t('installations.device'), className: 'mono', render: (row) => row.device.udid_hint },
      { key: 'version', label: t('installations.version'), render: (row) => `${row.version ?? '—'} (${row.build_number ?? '—'})` },
      { key: 'status', label: t('installations.status'), render: (row) => statusBadge(row.status, t(`installations.statuses.${row.status}`)) },
      { key: 'reason', label: t('installations.reason'), className: 'mono', render: (row) => row.status_reason ?? '' },
      { key: 'updated_at', label: t('installations.updated'), sortable: true, render: (row) => formatDateTime(row.updated_at) },
      { key: 'actions', label: '', render: (row) => el('button', { type: 'button', className: 'button', onclick: () => openInstallation(row.id) }, t('common.open')) },
    ],
    fetchPage: async ({ page, perPage, query, filters, signal }) => {
      const params = new URLSearchParams({ page, per_page: perPage, ...filters });
      if (query) params.set('q', query);
      const { data, meta } = await api.get(`/admin/installations?${params}`, { signal });
      return { rows: data, pagination: meta.pagination };
    },
  });
}

async function openInstallation(id) {
  const panel = openDialog(t, { title: t('common.loading'), body: el('p', {}, t('common.loading')), wide: true });
  try {
    const { data } = await api.get(`/admin/installations/${id}`);
    panel.dialog.querySelector('h2').textContent = `${data.app.name} · ${data.user.email}`;
    panel.setBody(
      el('dl', { className: 'details' },
        [[t('installations.status'), statusBadge(data.status, t(`installations.statuses.${data.status}`))], [t('installations.device'), data.device.udid_hint],
          [t('installations.build'), data.signed_build ? `${data.signed_build.status}${data.signed_build.status_reason ? ` · ${data.signed_build.status_reason}` : ''}` : '—']]
          .map(([term, value]) => el('div', {}, el('dt', {}, term), el('dd', {}, value)))),
      el('h3', {}, t('installations.timeline')),
      el('ol', { className: 'plain-list' }, data.events.map((event) => el('li', {},
        el('span', { className: 'muted' }, formatDateTime(event.at)), ' ', el('span', { className: 'mono' }, event.type),
        event.request_id ? el('span', { className: 'mono muted' }, ` · ${event.request_id}`) : ''))),
    );
  } catch (error) {
    panel.setBody(errorNotice(t, error));
  }
}

loadRunners();
setInterval(loadRunners, 30000);
