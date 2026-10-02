import { boot, el, errorNotice, formatDateTime, newIdempotencyKey, setupTabs } from '../app.js';
import { confirmAction } from '../components/confirm-dialog.js';
import { createDataTable } from '../components/data-table.js';
import { openDialog } from '../components/dialog.js';
import { statusBadge } from '../components/status-badge.js';
import { toast } from '../components/toast.js';

// Batch IPA uploader, provenance review queue, quarantine and publishing
// (IMPLEMENTATION_PLAN P5-ADM-01). Every file is independent: its own upload
// session, artifact, pipeline job and audit trail.
const { api, t, can } = await boot();
const manage = can('artifacts.manage');
const SOURCE_TYPES = ['OWN_BUILD', 'PARTNER_BUILD', 'OPEN_SOURCE_BUILD', 'ALTERNATIVE_MARKETPLACE_PACKAGE', 'USER_IMPORT', 'CUSTOMER_PROVIDED'];
const STATUSES = [
  'UPLOADED', 'HASHING', 'INSPECTING', 'PROVENANCE_REVIEW', 'COMPATIBILITY_CHECK', 'READY', 'PUBLISHED',
  'REJECTED', 'INSPECTION_FAILED', 'PROVENANCE_FAILED', 'QUARANTINED', 'REVOKED', 'EXPIRED',
];
const IN_FLIGHT = ['UPLOADED', 'HASHING', 'INSPECTING'];
const PARALLEL_FILES = 2;

setupTabs((id) => id !== 'tab-upload' || manage);

const statusLabel = (status) => t(`artifacts.status.${status}`);
const artifactBadge = (status) => statusBadge(status, statusLabel(status));
const formatSize = (bytes) => (bytes >= 1024 ** 3 ? `${(bytes / 1024 ** 3).toFixed(2)} ГБ` : `${(bytes / 1024 ** 2).toFixed(1)} МБ`);

async function sha256Hex(blob) {
  if (!crypto?.subtle) return null; // Not a secure context: the server still checks sizes and the final hash.
  const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

/* ---------- Uploader ---------- */

const form = document.querySelector('#upload-form');
const queueCard = document.querySelector('#upload-queue-card');
const queueList = document.querySelector('#upload-queue');
const appSelect = form.querySelector('[name="app_id"]');
const sourceSelect = form.querySelector('[name="source_type"]');
let apps = [];

document.querySelector('#declaration-text').textContent = t('artifacts.declaration');
sourceSelect.replaceChildren(...SOURCE_TYPES.map((type) => el('option', { value: type }, t(`sourceTypes.${type}`))));

async function loadApps() {
  try {
    const { data } = await api.get('/admin/apps?per_page=100');
    apps = data;
    appSelect.replaceChildren(
      el('option', { value: '' }, data.length ? t('artifacts.chooseApp') : t('artifacts.noApps')),
      ...data.map((app) => el('option', { value: app.id }, app.name)),
    );
    // artifacts.html?app=… (the «Загрузить IPA» button on a card) chooses the app.
    const preselected = new URLSearchParams(location.search).get('app');
    if (preselected && apps.some((app) => app.id === preselected)) {
      appSelect.value = preselected;
      appSelect.dispatchEvent(new Event('change'));
    }
  } catch (error) {
    form.querySelector('.form-status').replaceChildren(errorNotice(t, error, loadApps));
  }
}

// The listing's source type is the default; a mismatch would fail the compatibility check.
appSelect.addEventListener('change', () => {
  const app = apps.find((candidate) => candidate.id === appSelect.value);
  if (app) sourceSelect.value = app.source_type;
});

class FileUpload {
  constructor(file, appId, sourceType) {
    Object.assign(this, { file, appId, sourceType, uploadId: null, chunkCount: 0, received: 0, state: 'queued', artifact: null, error: null, controller: null });
    this.row = el('li', { className: 'upload-row' });
    queueList.append(this.row);
    this.render();
  }

  get percent() {
    return this.chunkCount ? Math.floor((this.received / this.chunkCount) * 100) : 0;
  }

  async run() {
    this.state = 'uploading';
    this.error = null;
    this.controller = new AbortController();
    this.render();

    try {
      if (!this.uploadId) {
        const { data } = await api.post('/admin/uploads', {
          app_id: this.appId,
          filename: this.file.name,
          size_bytes: this.file.size,
          source_type: this.sourceType,
          declaration_version: t('artifacts.declarationVersion'),
          declaration_accepted: true,
        });
        Object.assign(this, { uploadId: data.id, chunkSize: data.chunk_size, chunkCount: data.chunk_count });
      }

      // Resume: ask the server which chunks it already has.
      const { data: session } = await api.get(`/admin/uploads/${this.uploadId}`);
      this.chunkSize = session.chunk_size;
      this.chunkCount = session.chunk_count;
      this.received = session.received_chunks.length;
      this.render();

      for (const number of session.missing_chunks) {
        const chunk = this.file.slice(number * this.chunkSize, Math.min((number + 1) * this.chunkSize, this.file.size));
        const hash = await sha256Hex(chunk);
        await api.put(`/admin/uploads/${this.uploadId}/chunks/${number}`, chunk, {
          signal: this.controller.signal,
          headers: hash ? { 'X-Chunk-SHA256': hash } : {},
        });
        this.received += 1;
        this.render();
      }

      this.state = 'assembling';
      this.render();
      const { data: artifact } = await api.post(`/admin/uploads/${this.uploadId}/complete`);
      this.artifact = artifact;
      await this.followInspection();
    } catch (error) {
      if (error?.name === 'AbortError') {
        this.state = 'paused';
      } else {
        this.state = 'failed';
        this.error = error;
        // A rejected upload session cannot be reused; a retry starts a new one.
        if (['UPLOAD_CORRUPT', 'DUPLICATE_ARTIFACT', 'CONFLICT'].includes(error.code)) this.uploadId = null;
      }
      this.render();
    }
  }

  async followInspection() {
    this.state = 'inspecting';
    this.render();
    // The upload answer has no status reason; the detail does. Poll while inspection runs.
    for (let attempt = 0; attempt < 120; attempt += 1) {
      const { data } = await api.get(`/admin/artifacts/${this.artifact.id}`);
      this.artifact = data;
      this.render();
      if (!IN_FLIGHT.includes(data.status)) break;
      await new Promise((resolve) => { setTimeout(resolve, Math.min(2000 + attempt * 500, 10000)); });
    }
    this.state = 'done';
    this.render();
    tables.forEach((table) => table.reload());
  }

  pause() {
    this.controller?.abort();
  }

  render() {
    const progress = el('progress', { max: 100, value: this.state === 'done' || this.state === 'inspecting' ? 100 : this.percent });
    progress.setAttribute('aria-label', this.file.name);

    const text = {
      queued: t('artifacts.queued'),
      uploading: t('artifacts.uploading', { percent: this.percent }),
      paused: t('artifacts.paused', { percent: this.percent }),
      assembling: t('artifacts.assembling'),
      inspecting: t('artifacts.inspecting'),
      done: '',
      failed: t('artifacts.failed'),
    }[this.state];

    const status = el('span', { className: 'upload-row__status' }, text);
    if (this.artifact && (this.state === 'done' || this.state === 'inspecting')) {
      status.append(' ', artifactBadge(this.artifact.status), this.artifact.status_reason ? el('span', { className: 'mono' }, ` ${this.artifact.status_reason}`) : '');
    }
    if (this.error) {
      status.append(' ', el('span', { className: 'badge badge--error' }, t.error(this.error)),
        this.error.details?.artifact_id ? el('span', { className: 'mono' }, ` → ${this.error.details.artifact_id}`) : '',
        this.error.requestId ? el('span', { className: 'mono muted' }, ` · ${this.error.requestId}`) : '');
    }

    const actions = el('span', { className: 'button-row' });
    if (this.state === 'uploading') actions.append(el('button', { type: 'button', className: 'button', onclick: () => this.pause() }, t('artifacts.pause')));
    if (this.state === 'paused') actions.append(el('button', { type: 'button', className: 'button', onclick: () => schedule(this) }, t('artifacts.resume')));
    if (this.state === 'failed') actions.append(el('button', { type: 'button', className: 'button', onclick: () => schedule(this) }, t('artifacts.retry')));
    if (this.artifact) actions.append(el('button', { type: 'button', className: 'button', onclick: () => openArtifact(this.artifact.id) }, t('artifacts.open')));

    this.row.replaceChildren(
      el('div', { className: 'upload-row__head' }, el('b', {}, this.file.name), el('span', { className: 'muted' }, formatSize(this.file.size))),
      progress,
      el('div', { className: 'upload-row__foot' }, status, actions),
    );
  }
}

// A small pool: at most PARALLEL_FILES files upload at the same time.
const waiting = [];
let active = 0;

function schedule(upload) {
  upload.state = 'queued';
  upload.render();
  waiting.push(upload);
  pump();
}

function pump() {
  while (active < PARALLEL_FILES && waiting.length) {
    const upload = waiting.shift();
    active += 1;
    upload.run().finally(() => {
      active -= 1;
      pump();
    });
  }
}

form.addEventListener('submit', (event) => {
  event.preventDefault();
  const status = form.querySelector('.form-status');
  status.replaceChildren();
  const files = [...form.querySelector('[name="files"]').files];
  const problems = [];
  if (!appSelect.value) problems.push(t('artifacts.chooseApp'));
  if (!files.length) problems.push(t('artifacts.filesRequired'));
  if (!form.querySelector('[name="declaration_accepted"]').checked) problems.push(t('artifacts.declarationRequired'));
  if (problems.length) {
    status.replaceChildren(el('div', { className: 'notice notice--error' }, el('p', {}, problems.join(' '))));
    return;
  }

  queueCard.hidden = false;
  files.forEach((file) => schedule(new FileUpload(file, appSelect.value, sourceSelect.value)));
  form.reset();
});

// Leaving the page would abort running uploads; they can be resumed only from this page.
window.addEventListener('beforeunload', (event) => {
  if (active > 0) event.preventDefault();
});

/* ---------- Queues ---------- */

function artifactTable(container, fixedStatus) {
  return createDataTable({
    container: document.querySelector(container),
    t,
    caption: t('artifacts.statusLabel'),
    searchLabel: t('artifacts.search'),
    filters: fixedStatus ? [] : [{ name: 'status[]', label: t('artifacts.statusLabel'), type: 'select', options: [['', t('artifacts.allStatuses')], ...STATUSES.map((s) => [s, statusLabel(s)])] }],
    columns: [
      { key: 'original_filename', label: t('artifacts.file') },
      { key: 'app', label: t('artifacts.app'), render: (row) => row.app?.name ?? '—' },
      { key: 'bundle', label: t('artifacts.bundle'), className: 'mono', render: (row) => row.bundle_identifier ?? '—' },
      { key: 'version', label: t('artifacts.version'), render: (row) => (row.version ? `${row.version} (${row.build_number})` : '—') },
      { key: 'status', label: t('artifacts.statusLabel'), render: (row) => artifactBadge(row.status) },
      { key: 'reason', label: t('artifacts.reason'), className: 'mono', render: (row) => row.status_reason ?? row.compatibility_issues.join(', ') },
      { key: 'created_at', label: t('artifacts.uploaded'), sortable: true, render: (row) => formatDateTime(row.created_at) },
      { key: 'actions', label: '', render: (row) => el('button', { type: 'button', className: 'button', onclick: () => openArtifact(row.id) }, t('artifacts.open')) },
    ],
    fetchPage: async ({ page, perPage, query, filters, signal }) => {
      const params = new URLSearchParams({ page, per_page: perPage });
      if (fixedStatus) params.set('status[]', fixedStatus);
      Object.entries(filters).forEach(([name, value]) => value && params.set(name, value));
      if (query) params.set('q', query);
      const { data, meta } = await api.get(`/admin/artifacts?${params}`, { signal });
      return { rows: data, pagination: meta.pagination };
    },
  });
}

const tables = [
  artifactTable('#review-table', 'PROVENANCE_REVIEW'),
  artifactTable('#quarantine-table', 'QUARANTINED'),
  artifactTable('#all-table', null),
];

/* ---------- Detail and decisions ---------- */

async function openArtifact(id) {
  const panel = openDialog(t, { title: t('common.loading'), body: el('p', {}, t('common.loading')), wide: true });

  async function render() {
    try {
      const { data } = await api.get(`/admin/artifacts/${id}`);
      panel.dialog.querySelector('h2').textContent = `${data.original_filename} · ${statusLabel(data.status)}`;
      panel.setBody(...detail(data, render, panel));
    } catch (error) {
      panel.setBody(errorNotice(t, error, render));
    }
  }

  await render();
}

const pairs = (list) => el('dl', { className: 'details' }, list.map(([term, value]) => el('div', {}, el('dt', {}, term), el('dd', {}, value ?? '—'))));

function detail(artifact, rerender, panel) {
  const report = artifact.inspection ?? {};
  const bundle = report.bundle ?? {};
  const main = (report.binaries ?? []).find((binary) => binary.role === 'main');
  const profile = report.embedded_profile;

  const nodes = [
    pairs([
      [t('artifacts.statusLabel'), artifactBadge(artifact.status)],
      [t('artifacts.reason'), artifact.status_reason],
      [t('artifacts.app'), artifact.app?.name],
      [t('artifacts.source'), t(`sourceTypes.${artifact.source_type}`)],
      [t('artifacts.bundle'), el('span', { className: 'mono' }, artifact.bundle_identifier ?? '—')],
      [t('artifacts.version'), artifact.version ? `${artifact.version} (${artifact.build_number})` : '—'],
      [t('artifacts.minIos'), artifact.min_ios_version],
      [t('artifacts.families'), (bundle.device_families ?? []).map((family) => ({ 1: 'iPhone', 2: 'iPad' }[family] ?? family)).join(', ')],
      [t('artifacts.architectures'), main ? main.architectures.join(', ') : null],
      [t('artifacts.size'), formatSize(artifact.size_bytes)],
      [t('artifacts.scan'), artifact.malware_scan],
      [t('artifacts.uploadedBy'), `${artifact.uploaded_by ?? '—'} · ${formatDateTime(artifact.created_at)}`],
      ['SHA-256', el('span', { className: 'mono' }, artifact.sha256)],
      [t('artifacts.profile'), profile?.readable ? `${profile.type} · ${profile.team_identifier ?? ''} · ${profile.expires_at ?? ''}` : t('artifacts.none')],
    ]),
  ];

  if (report.failure) {
    nodes.push(el('div', { className: 'notice notice--error' }, el('b', {}, `${t('artifacts.failure')}: ${report.failure.code}`), el('p', {}, report.failure.message),
      Object.keys(report.failure.details ?? {}).length ? el('p', { className: 'mono' }, JSON.stringify(report.failure.details)) : ''));
  }
  if (report.compatibility_issues?.length) {
    nodes.push(el('h3', {}, t('artifacts.issues')), el('ul', { className: 'plain-list' }, report.compatibility_issues.map((issue) => el('li', {}, el('span', { className: 'mono' }, issue.code), ` — ${issue.message}`))));
  }
  if (report.compatibility) {
    nodes.push(el('h3', {}, t('artifacts.compatibility')), el('ul', { className: 'plain-list' },
      ...report.compatibility.blocking.map((item) => el('li', {}, statusBadge('FAILED', t('artifacts.blocking')), ` ${item.code} — ${item.message}`)),
      ...report.compatibility.warnings.map((item) => el('li', {}, statusBadge('warn', t('artifacts.warnings')), ` ${item.code} — ${item.message}`))));
  }
  if (report.entitlements) {
    nodes.push(el('h3', {}, t('artifacts.entitlements')), el('p', { className: 'mono' }, Object.keys(report.entitlements).join(', ') || t('artifacts.none')));
  }
  nodes.push(...cleaningSection(artifact));

  if (manage && artifact.available_actions.length) nodes.push(actions(artifact, rerender, panel));

  nodes.push(el('h3', {}, t('artifacts.documents')));
  nodes.push(artifact.documents.length
    ? el('ul', { className: 'plain-list' }, artifact.documents.map((document) => el('li', {},
      el('a', { href: document.download_url }, document.filename), ` · ${document.description ?? ''} · ${formatDateTime(document.created_at)}`)))
    : el('p', { className: 'muted' }, t('artifacts.none')));
  if (manage) nodes.push(documentForm(artifact, rerender));

  if (artifact.reviews.length) {
    nodes.push(el('h3', {}, t('artifacts.reviews')), el('ul', { className: 'plain-list' }, artifact.reviews.map((review) => el('li', {},
      el('span', { className: 'muted' }, formatDateTime(review.created_at)), ` ${review.decision} · ${review.reviewer ?? ''}`, review.reason ? ` — ${review.reason}` : ''))));
  }
  if (artifact.jobs.length) {
    nodes.push(el('h3', {}, t('artifacts.jobs')), el('ul', { className: 'plain-list' }, artifact.jobs.map((job) => el('li', { className: 'mono' },
      `${job.type} · ${job.status} · ${job.result_code ?? job.error_message ?? ''}`))));
  }
  nodes.push(el('h3', {}, t('artifacts.history')), el('ul', { className: 'plain-list' }, artifact.history.map((entry) => el('li', {},
    el('span', { className: 'muted' }, formatDateTime(entry.occurred_at)), ' ', el('span', { className: 'mono' }, entry.action),
    entry.after?.status ? ` → ${entry.after.status}` : '', entry.reason ? ` — ${entry.reason}` : ''))));

  return nodes;
}

function actions(artifact, rerender, panel) {
  const row = el('div', { className: 'button-row' });
  const decide = async (request, done) => {
    try {
      await request();
      toast(t('artifacts.done'), { tone: 'ok' });
      tables.forEach((table) => table.reload());
      await rerender();
      done?.();
    } catch (error) {
      toast(t.error(error) + (error.requestId ? ` · ${error.requestId}` : ''), { tone: 'error' });
    }
  };
  const withReason = (title, message, danger, send) => async () => {
    const { confirmed, reason } = await confirmAction(t, { title, message, danger, requireReason: true });
    if (confirmed) await decide(() => send(reason));
  };
  const button = (label, handler, kind = '') => row.append(el('button', { type: 'button', className: `button ${kind}`, onclick: handler }, label));
  const post = (action, body) => api.post(`/admin/artifacts/${artifact.id}/${action}`, body, { idempotencyKey: newIdempotencyKey(`artifact-${action}`) });

  for (const action of artifact.available_actions) {
    if (action === 'approve') button(t('artifacts.approve'), () => approveDialog(artifact, decide, post), 'button--primary');
    if (action === 'release') button(t('artifacts.release'), withReason(t('artifacts.releaseTitle'), t('artifacts.releaseMessage'), false, (reason) => post('review', { decision: 'approve', reason })));
    if (action === 'reject') button(t('artifacts.reject'), withReason(t('artifacts.rejectTitle'), t('artifacts.rejectMessage'), true, (reason) => post('review', { decision: 'reject', reason })), 'button--danger');
    if (action === 'publish') {
      button(t('artifacts.publish'), async () => {
        const { confirmed } = await confirmAction(t, { title: t('artifacts.publishTitle'), message: t('artifacts.publishMessage') });
        if (confirmed) await decide(() => post('publish'));
      }, 'button--primary');
    }
    if (action === 'revoke') button(t('artifacts.revoke'), withReason(t('artifacts.revokeTitle'), t('artifacts.revokeMessage'), true, (reason) => post('revoke', { reason })), 'button--danger');
    if (action === 'inspect') button(t('artifacts.inspect'), withReason(t('artifacts.inspectTitle'), t('artifacts.inspectMessage'), false, (reason) => post('inspect', { reason })));
    if (action === 'clean') button(t('artifacts.clean'), () => cleanDialog(artifact, rerender));
  }
  return row;
}

/* ---------- IPA cleaning (tools/ipa-cleaner) ---------- */

const fileName = (path) => path.replace(/\/$/, '').split('/').pop();
const categoryLabel = (category) => (t.has(`artifacts.cleaning.category.${category}`) ? t(`artifacts.cleaning.category.${category}`) : category);

/** What inspection found, what a cleaned copy had removed, and the copies made from this file. */
function cleaningSection(artifact) {
  const analysis = artifact.inspection?.cleaning;
  const nodes = [el('h3', {}, t('artifacts.cleaning.title'))];

  if (artifact.derived_from) {
    const report = artifact.cleaning_report ?? {};
    const lines = [
      [report.removed_modules?.map((module) => fileName(module.path)), 'removedModules'],
      [report.removed_extensions?.map(fileName), 'removedExtensions'],
      [Object.keys(report.patched_libraries ?? {}).map(fileName), 'patchedLibraries'],
      [report.metadata_fixes?.map((fix) => `${fix.key}: ${fix.from} → ${fix.to}`), 'metadataFixes'],
    ].filter(([list]) => list?.length).map(([list, key]) => el('p', {}, t(`artifacts.cleaning.${key}`, { list: list.join(', ') })));
    nodes.push(el('div', { className: 'notice' },
      el('p', {}, t('artifacts.cleaning.derivedFrom', { id: artifact.derived_from.id }), ' ',
        el('button', { type: 'button', className: 'button', onclick: () => openArtifact(artifact.derived_from.id) }, t('artifacts.open'))),
      ...lines));
  }
  if (artifact.cleaned_copies?.length) {
    nodes.push(el('p', {}, el('b', {}, t('artifacts.cleaning.copies'))), el('ul', { className: 'plain-list' }, artifact.cleaned_copies.map((copy) => el('li', {},
      artifactBadge(copy.status), ' ', el('span', { className: 'mono' }, copy.id), ' ',
      el('button', { type: 'button', className: 'button', onclick: () => openArtifact(copy.id) }, t('artifacts.open'))))));
  }
  if (!analysis) {
    nodes.push(el('p', { className: 'muted' }, t('artifacts.cleaning.notAnalyzed')));
    return nodes;
  }
  if (analysis.error) {
    nodes.push(el('div', { className: 'notice notice--error' }, el('p', {}, analysis.error)));
    return nodes;
  }

  const extensions = (analysis.extensions ?? []).filter((extension) => extension.encrypted);
  if (!analysis.modules.length && !extensions.length && !analysis.metadata_issues.length && !analysis.library_patches.length) {
    nodes.push(el('p', { className: 'muted' }, t('artifacts.cleaning.nothingFound')));
  }
  if (analysis.modules.length) {
    nodes.push(el('p', {}, el('b', {}, t('artifacts.cleaning.modules'))), el('ul', { className: 'plain-list cleaning-list' }, analysis.modules.map((module) => {
      const details = [module.reason, ...(module.telegram_links ?? []), ...(module.other_markers ?? []), module.hook_markers?.join(', '), module.blocked_reason,
        module.loaded_by?.length ? t('artifacts.cleaning.loadedBy', { list: module.loaded_by.map(fileName).join(', ') }) : null].filter(Boolean);
      return el('li', {},
        el('span', { className: 'mono' }, fileName(module.path)), ' ', el('span', { className: 'muted' }, `· ${categoryLabel(module.category)}`), ' ',
        module.recommended ? statusBadge('ok', t('artifacts.cleaning.recommendedBadge')) : '',
        module.removable ? '' : statusBadge('BLOCKED', t('artifacts.cleaning.blocked')),
        module.encrypted ? statusBadge('warn', t('artifacts.cleaning.encrypted')) : '',
        details.length ? el('div', { className: 'muted cleaning-list__details' }, details.join(' · ')) : '');
    })));
  }
  if (extensions.length) {
    nodes.push(el('p', {}, el('b', {}, t('artifacts.cleaning.extensions'))), el('ul', { className: 'plain-list' }, extensions.map((extension) => el('li', {},
      el('span', { className: 'mono' }, fileName(extension.path)), ` · ${extension.point ?? extension.bundle_id ?? ''} `, statusBadge('warn', t('artifacts.cleaning.encrypted'))))));
  }
  if (analysis.library_patches.length) {
    nodes.push(el('p', {}, el('b', {}, t('artifacts.cleaning.patches'))), el('ul', { className: 'plain-list' }, analysis.library_patches.map((patch) => el('li', {},
      el('span', { className: 'mono' }, fileName(patch.path)), ` · ${patch.reason} · ${t(`artifacts.cleaning.patchState.${patch.state}`)}`))));
  }
  if (analysis.metadata_issues.length) {
    nodes.push(el('p', {}, el('b', {}, t('artifacts.cleaning.metadata'))), el('ul', { className: 'plain-list' }, analysis.metadata_issues.map((issue) => el('li', { className: 'mono' },
      `${issue.code}: ${issue.value} → ${issue.fix}`))));
  }
  if (analysis.native_ad_sdk_markers?.length) {
    nodes.push(el('p', { className: 'muted' }, `${t('artifacts.cleaning.adSdks')}: ${analysis.native_ad_sdk_markers.join(', ')}`));
  }
  return nodes;
}

function cleanDialog(artifact, rerender) {
  const analysis = artifact.inspection?.cleaning?.error ? null : artifact.inspection?.cleaning;
  const optional = (analysis?.modules ?? []).filter((module) => module.removable && !module.recommended);
  const extensions = analysis?.extensions ?? [];
  const check = (name, value, label, checked = false) => el('label', { className: 'check' }, el('input', { type: 'checkbox', name, value, checked }), ` ${label}`);

  const form = el('form', { className: 'form-grid', noValidate: true },
    el('p', { className: 'muted' }, t('artifacts.cleaning.dialogIntro')),
    check('recommended', '1', t('artifacts.cleaning.recommended'), true),
    analysis ? '' : el('p', { className: 'muted' }, t('artifacts.cleaning.notAnalyzed')),
    optional.length || extensions.length ? el('p', {}, el('b', {}, t('artifacts.cleaning.choose'))) : '',
    ...optional.map((module) => check('remove', module.path, `${fileName(module.path)} — ${categoryLabel(module.category)}`)),
    optional.some((module) => ['suspected-hook', 'hook-runtime'].includes(module.category)) ? el('p', { className: 'muted' }, t('artifacts.cleaning.riskNote')) : '',
    ...extensions.map((extension) => check('remove_extensions', extension.path,
      `${fileName(extension.path)}${extension.encrypted ? ` (${t('artifacts.cleaning.encrypted')})` : ''}${extension.point ? ` — ${extension.point}` : ''}`)),
    extensions.length ? el('p', { className: 'muted' }, t('artifacts.cleaning.extensionNote')) : '',
    analysis?.metadata_issues?.length ? check('fix_metadata', '1', t('artifacts.cleaning.fixMetadata')) : '',
    el('label', { className: 'field' }, t('artifacts.cleaning.reason'), el('input', { name: 'reason', required: true, maxLength: 2000 })),
    el('div', { className: 'button-row' }, el('button', { type: 'submit', className: 'button button--primary' }, t('artifacts.cleaning.submit'))),
    el('div', { className: 'form-status', role: 'status', ariaLive: 'polite' }));
  openDialog(t, { title: `${t('artifacts.cleaning.title')} · ${artifact.original_filename}`, body: form, wide: true });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    const status = form.querySelector('.form-status');
    const submit = form.querySelector('button[type="submit"]');
    const data = new FormData(form);
    submit.disabled = true;
    try {
      const { data: started } = await api.post(`/admin/artifacts/${artifact.id}/clean`, {
        recommended: data.has('recommended'),
        remove: data.getAll('remove'),
        remove_extensions: data.getAll('remove_extensions'),
        fix_metadata: data.has('fix_metadata'),
        reason: data.get('reason'),
      }, { idempotencyKey: newIdempotencyKey('artifact-clean') });
      toast(t('artifacts.cleaning.started'), { tone: 'ok' });
      status.replaceChildren(el('p', { className: 'muted' }, t('artifacts.cleaning.waiting')));
      const { job, detail } = await followCleaning(artifact.id, started.job.id);
      tables.forEach((table) => table.reload());
      await rerender();
      if (job?.status === 'SUCCEEDED') {
        const copy = job.result_code === 'CLEANED' ? detail.cleaned_copies[0] : null;
        status.replaceChildren(el('div', { className: 'notice' }, el('p', {}, t.has(`artifacts.cleaning.result.${job.result_code}`) ? t(`artifacts.cleaning.result.${job.result_code}`) : job.result_code),
          copy ? el('button', { type: 'button', className: 'button button--primary', onclick: () => openArtifact(copy.id) }, t('artifacts.cleaning.openCopy')) : ''));
      } else {
        status.replaceChildren(el('div', { className: 'notice notice--error' }, el('p', {}, t('artifacts.cleaning.failedResult', { message: job?.error_message ?? job?.status ?? '—' }))));
        submit.disabled = false;
      }
    } catch (error) {
      status.replaceChildren(errorNotice(t, error));
      submit.disabled = false;
    }
  });
}

/** The cleaning job runs on the files queue: follow it through the artifact detail until it settles. */
async function followCleaning(artifactId, jobId) {
  for (let attempt = 0; attempt < 240; attempt += 1) {
    const { data } = await api.get(`/admin/artifacts/${artifactId}`);
    const job = data.jobs.find((candidate) => candidate.id === jobId);
    if (job && ['SUCCEEDED', 'FAILED_PERMANENT', 'FAILED_RETRYABLE', 'CANCELLED'].includes(job.status)) return { job, detail: data };
    await new Promise((resolve) => { setTimeout(resolve, Math.min(1500 + attempt * 250, 5000)); });
  }
  return { job: null, detail: null };
}

/** Provenance approval: every checklist item, and the scan acknowledgement unless the scan was clean. */
function approveDialog(artifact, decide, post) {
  const items = artifact.review_checklist.items;
  const needsAck = artifact.malware_scan !== 'CLEAN';
  const approveForm = el('form', { className: 'form-grid', noValidate: true },
    el('p', { className: 'muted' }, `${t('artifacts.checklistTitle')} · ${artifact.review_checklist.version}`),
    ...items.map((item) => el('label', { className: 'check' }, el('input', { type: 'checkbox', name: item, required: true }), ` ${t.has(`artifacts.checklist.${item}`) ? t(`artifacts.checklist.${item}`) : item}`)),
    needsAck ? el('label', { className: 'check' }, el('input', { type: 'checkbox', name: 'acknowledge_scan_result', required: true }), ` ${t('artifacts.ackScan', { status: artifact.malware_scan ?? '—' })}`) : '',
    el('label', { className: 'field' }, t('artifacts.comment'), el('input', { name: 'reason', maxLength: 2000 })),
    el('div', { className: 'button-row' }, el('button', { type: 'submit', className: 'button button--primary' }, t('artifacts.confirmApprove'))),
  );
  const dialog = openDialog(t, { title: t('artifacts.approve'), body: approveForm });

  approveForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!approveForm.reportValidity()) return;
    const checklist = Object.fromEntries(items.map((item) => [item, approveForm.elements[item].checked]));
    await decide(() => post('review', {
      decision: 'approve',
      checklist,
      acknowledge_scan_result: needsAck ? approveForm.elements.acknowledge_scan_result.checked : false,
      reason: approveForm.elements.reason.value || null,
    }), () => dialog.close());
  });
}

function documentForm(artifact, rerender) {
  const documentForm = el('form', { className: 'form-grid', noValidate: true },
    el('label', { className: 'field' }, t('artifacts.addDocument'), el('input', { type: 'file', name: 'file', accept: '.pdf,.png,.jpg,.jpeg,.txt', required: true })),
    el('label', { className: 'field' }, t('artifacts.documentDescription'), el('input', { name: 'description', maxLength: 500 })),
    el('div', { className: 'button-row' }, el('button', { type: 'submit', className: 'button' }, t('artifacts.addDocument'))),
    el('div', { className: 'form-status', role: 'status' }));
  documentForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!documentForm.reportValidity()) return;
    try {
      await api.post(`/admin/artifacts/${artifact.id}/documents`, new FormData(documentForm));
      toast(t('artifacts.documentAdded'), { tone: 'ok' });
      await rerender();
    } catch (error) {
      documentForm.querySelector('.form-status').replaceChildren(errorNotice(t, error));
    }
  });
  return documentForm;
}

if (manage) loadApps();
