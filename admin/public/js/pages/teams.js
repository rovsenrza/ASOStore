import { boot, el, errorNotice, formatDate, formatDateTime, newIdempotencyKey, setupTabs } from '../app.js';
import { confirmAction } from '../components/confirm-dialog.js';
import { openDialog } from '../components/dialog.js';
import { statusBadge } from '../components/status-badge.js';
import { toast } from '../components/toast.js';

// Apple teams, quotas, app eligibility and team-assignment approvals (P7-ADM-01).
const { api, t, can } = await boot();
const manage = can('teams.manage');
setupTabs();

const teamsBox = document.querySelector('#teams');
const approvalsBox = document.querySelector('#approvals');
const banner = document.querySelector('#blocking-banner');
let teams = [];

async function reasonFor(title = t('teams.reasonTitle'), message = t('teams.reasonMessage')) {
  const { confirmed, reason } = await confirmAction(t, { title, message, requireReason: true });
  return confirmed ? reason : null;
}

async function act(request, success) {
  try {
    const result = await request();
    toast(success(result), { tone: 'ok' });
    await loadTeams();
  } catch (error) {
    toast(t.error(error) + (error.requestId ? ` · ${error.requestId}` : ''), { tone: 'error' });
  }
}

// Teams ---------------------------------------------------------------------
async function loadTeams() {
  try {
    const { data, meta } = await api.get('/admin/apple-teams');
    teams = data;
    teamsBox.replaceChildren(...(data.length ? data.map(teamCard) : [el('p', { className: 'muted' }, t('teams.empty'))]));
    renderBanner(meta.pending_assignments ?? 0);
    fillTeamSelect();
  } catch (error) {
    teamsBox.replaceChildren(errorNotice(t, error, loadTeams));
  }
}

function renderBanner(pending) {
  document.querySelector('#approvals-count').textContent = pending ? `(${pending})` : '';
  banner.hidden = pending === 0;
  banner.className = 'notice notice--error';
  banner.replaceChildren(el('b', {}, t('teams.blockingTitle')), el('p', {}, t('teams.blockingPending', { count: pending })));
}

function quotaBar(quota) {
  const used = quota.registered + quota.reserved;
  const meter = el('meter', { min: 0, max: quota.limit, value: used, low: quota.limit * 0.8, high: quota.limit * 0.95, optimum: 0 });
  meter.setAttribute('aria-label', quota.family);
  return el('li', {},
    el('div', {}, t('teams.quota', { family: quota.family, used, limit: quota.limit, remaining: quota.remaining })),
    meter,
    quota.apple_registered !== null && quota.apple_registered !== quota.registered
      ? el('div', { className: 'badge badge--warn' }, t('teams.quotaApple', { count: quota.apple_registered }))
      : '');
}

function teamCard(team) {
  const card = el('article', { className: 'card team-card' },
    el('h2', {}, `${team.name} · ${team.apple_team_id} `, statusBadge(team.status, t(`teams.status.${team.status}`)), team.is_primary ? ` ${t('teams.primary')}` : ''),
    el('p', {}, team.credential ? t('teams.credential', { key: team.credential.key_id }) : t('teams.noCredential')),
    el('p', { className: 'muted' }, team.last_verified_at ? t('teams.verified', { time: formatDateTime(team.last_verified_at) }) : t('teams.notVerified')),
    el('p', {}, team.membership_year
      ? t('teams.membership', { from: formatDate(team.membership_year.starts_at), to: formatDate(team.membership_year.ends_at) })
      : t('teams.noMembership')),
    team.quotas.length ? el('ul', { className: 'plain-list quota-list' }, team.quotas.map(quotaBar)) : el('p', { className: 'muted' }, t('teams.noQuota')),
    el('h3', {}, t('teams.eligible')),
    el('p', { className: 'mono' }, team.eligibilities.length ? team.eligibilities.join(', ') : t('teams.none')),
    el('h3', {}, t('teams.certificates')),
    el('ul', { className: 'plain-list' }, team.certificates.map((certificate) => el('li', { className: 'mono' },
      `${certificate.sha1.slice(0, 8)}… ${certificate.common_name} · ${formatDate(certificate.expires_at)} · `,
      statusBadge(certificate.on_runner ? 'ok' : '', certificate.on_runner ? t('teams.onRunner') : t('teams.notOnRunner'))))),
    el('p', { className: 'muted' }, t('teams.profiles', { active: team.profiles.active, soon: team.profiles.expiring_soon })),
  );

  if (manage) card.append(teamActions(team));
  return card;
}

function teamActions(team) {
  const row = el('div', { className: 'button-row' });
  const button = (label, handler, kind = '') => row.append(el('button', { type: 'button', className: `button ${kind}`, onclick: handler }, label));

  button(t('teams.verify'), () => act(() => api.post(`/admin/apple-teams/${team.id}/verify`), () => t('teams.verifiedToast')));
  button(t('teams.sync'), () => act(() => api.post(`/admin/apple-teams/${team.id}/sync`), ({ data }) => t('teams.synced', { mismatches: data.reconciliation.mismatches })));
  button(t('teams.addCredential'), () => credentialDialog(team));
  button(t('teams.addYear'), () => yearDialog(team));
  if (team.status !== 'ACTIVE') {
    button(t('teams.activate'), async () => {
      const reason = await reasonFor();
      if (reason) act(() => api.patch(`/admin/apple-teams/${team.id}`, { status: 'ACTIVE', reason }), () => t('teams.saved'));
    }, 'button--primary');
  } else {
    button(t('teams.suspend'), async () => {
      const reason = await reasonFor();
      if (reason) act(() => api.patch(`/admin/apple-teams/${team.id}`, { status: 'SUSPENDED', reason }), () => t('teams.saved'));
    }, 'button--danger');
  }
  if (!team.is_primary) {
    button(t('teams.makePrimary'), async () => {
      const reason = await reasonFor();
      if (reason) act(() => api.patch(`/admin/apple-teams/${team.id}`, { is_primary: true, reason }), () => t('teams.saved'));
    });
  }
  return row;
}

function formDialog(title, fields, submit) {
  const form = el('form', { className: 'form-grid', noValidate: true },
    ...fields.map(([name, label, type = 'text']) => el('label', { className: 'field' }, label, el('input', { name, type, required: true, autocomplete: 'off' }))),
    el('div', { className: 'button-row' }, el('button', { type: 'submit', className: 'button button--primary' }, t('teams.create'))),
    el('div', { className: 'form-status', role: 'status' }));
  const panel = openDialog(t, { title, body: form });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await submit(Object.fromEntries(new FormData(form)));
      panel.close();
      toast(t('teams.saved'), { tone: 'ok' });
      await loadTeams();
    } catch (error) {
      form.querySelector('.form-status').replaceChildren(errorNotice(t, error));
    }
  });
}

function credentialDialog(team) {
  formDialog(`${t('teams.addCredential')} · ${team.apple_team_id}`,
    [['issuer_id', t('teams.issuer')], ['key_id', t('teams.keyId')], ['vault_reference', t('teams.vault')]],
    (values) => api.post(`/admin/apple-teams/${team.id}/credentials`, values));
}

function yearDialog(team) {
  formDialog(`${t('teams.addYear')} · ${team.apple_team_id}`,
    [['starts_at', t('teams.starts'), 'date'], ['ends_at', t('teams.ends'), 'date']],
    (values) => api.post(`/admin/apple-teams/${team.id}/membership-years`, values));
}

const addTeam = document.querySelector('#add-team');
addTeam.hidden = !manage;
addTeam.addEventListener('click', () => formDialog(t('teams.addTitle'),
  [['apple_team_id', t('teams.teamId')], ['name', t('teams.name')]],
  (values) => api.post('/admin/apple-teams', { ...values, apple_team_id: values.apple_team_id.trim().toUpperCase() })));

// Approvals -----------------------------------------------------------------
async function loadApprovals() {
  try {
    const { data } = await api.get('/admin/quota-assignments?status=PENDING');
    approvalsBox.replaceChildren(...(data.length ? data.map(approvalCard) : [el('p', { className: 'muted' }, t('teams.approvalsEmpty'))]));
  } catch (error) {
    approvalsBox.replaceChildren(errorNotice(t, error, loadApprovals));
  }
}

function approvalCard(assignment) {
  const card = el('article', { className: 'card' },
    el('h2', {}, `${assignment.device.udid_hint} · ${assignment.device.family} · ${assignment.device.owner}`),
    el('dl', { className: 'details' },
      [[t('teams.from'), assignment.from_team], [t('teams.to'), `${assignment.to_team.name} · ${assignment.to_team.apple_team_id}`]]
        .map(([term, value]) => el('div', {}, el('dt', {}, term), el('dd', { className: 'mono' }, value)))),
    el('p', {}, el('b', {}, `${t('teams.why')}: `), assignment.selection_reason));

  if (manage) {
    const decide = (kind) => async () => {
      const approving = kind === 'approve';
      const { confirmed, reason } = await confirmAction(t, {
        title: t(approving ? 'teams.approveTitle' : 'teams.rejectTitle'),
        message: approving ? t('teams.approveMessage', { device: assignment.device.udid_hint, team: assignment.to_team.apple_team_id }) : t('teams.rejectMessage'),
        danger: !approving,
        requireReason: true,
      });
      if (!confirmed) return;
      await act(() => api.post(`/admin/quota-assignments/${assignment.id}/${kind}`, { reason }, { idempotencyKey: newIdempotencyKey(`assignment-${kind}`) }), () => t('teams.decided'));
      await loadApprovals();
    };
    card.append(el('div', { className: 'button-row' },
      el('button', { type: 'button', className: 'button button--primary', onclick: decide('approve') }, t('teams.approve')),
      el('button', { type: 'button', className: 'button button--danger', onclick: decide('reject') }, t('teams.reject'))));
  }
  return card;
}

// Eligibility ---------------------------------------------------------------
const eligibilityForm = document.querySelector('#eligibility-form');
const eligibilitiesBox = document.querySelector('#eligibilities');
eligibilityForm.hidden = !manage;

function fillTeamSelect() {
  const select = eligibilityForm.querySelector('select');
  select.replaceChildren(...teams.map((team) => el('option', { value: team.id }, `${team.name} · ${team.apple_team_id}`)));
}

async function loadEligibilities() {
  try {
    const { data } = await api.get('/admin/team-eligibilities');
    if (!data.length) {
      eligibilitiesBox.replaceChildren(el('p', { className: 'muted' }, t('teams.eligibilityEmpty')));
      return;
    }
    eligibilitiesBox.replaceChildren(el('ul', { className: 'plain-list' }, data.map((row) => {
      const item = el('li', {},
        statusBadge(row.status === 'APPROVED' ? 'ok' : 'REVOKED', row.status), ' ',
        el('span', { className: 'mono' }, `${row.bundle_identifier} → ${row.team.apple_team_id}`),
        ` · ${t('teams.evidence')}: ${row.evidence} · ${t('teams.approvedBy')}: ${row.approved_by ?? '—'}`);
      if (manage && row.status === 'APPROVED') {
        item.append(' ', el('button', { type: 'button', className: 'button button--danger', onclick: async () => {
          const { confirmed, reason } = await confirmAction(t, { title: t('teams.revokeTitle'), message: t('teams.revokeMessage'), danger: true, requireReason: true });
          if (!confirmed) return;
          await act(() => api.post(`/admin/team-eligibilities/${row.id}/revoke`, { reason }), () => t('teams.saved'));
          await loadEligibilities();
        } }, t('teams.revoke')));
      }
      return item;
    })));
  } catch (error) {
    eligibilitiesBox.replaceChildren(errorNotice(t, error, loadEligibilities));
  }
}

eligibilityForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const status = eligibilityForm.querySelector('.form-status');
  try {
    await api.post('/admin/team-eligibilities', Object.fromEntries(new FormData(eligibilityForm)));
    eligibilityForm.reset();
    status.replaceChildren();
    toast(t('teams.saved'), { tone: 'ok' });
    await Promise.all([loadEligibilities(), loadTeams()]);
  } catch (error) {
    status.replaceChildren(errorNotice(t, error));
  }
});

await loadTeams();
loadApprovals();
loadEligibilities();
