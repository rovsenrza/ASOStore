import { boot, el, errorNotice, formatDateTime } from '../app.js';
import { statusBadge } from '../components/status-badge.js';

// «Быстрая публикация»: drop IPA files or a whole folder; each file is uploaded in chunks and the
// server takes it through inspection, cleaning, listing, approval and publication. Every file is
// independent: its own upload, pipeline job and audit trail.
const { api, t, can } = await boot();

const PARALLEL_FILES = 2;
const STEPS = t.list('publish.steps');
// Where each server stage sits in the five-step tracker.
const STEP_OF = { UPLOADED: 0, INSPECTING: 1, CLEANING: 2, PUBLISHING: 3, PUBLISHED: 4 };
const STOPPED = new Set(['HELD', 'DUPLICATE']);
const FAILED = new Set(['REJECTED', 'FAILED']);
const POLL_MS = 3000;

const formatSize = (bytes) => (bytes >= 1024 ** 3 ? `${(bytes / 1024 ** 3).toFixed(2)} ГБ` : `${(bytes / 1024 ** 2).toFixed(1)} МБ`);
const stageLabel = (stage) => (t.has(`publish.stage.${stage}`) ? t(`publish.stage.${stage}`) : stage);

async function sha256Hex(blob) {
  if (!crypto?.subtle) return null; // Not a secure context: the server still checks sizes and the final hash.
  const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

function main() {
  if (!(can('catalog.manage') && can('artifacts.manage'))) {
    document.querySelector('#main').replaceChildren(errorNotice(t, { code: 'FORBIDDEN' }));
    return;
  }

  const form = document.querySelector('#publish-form');
  const status = form.querySelector('.form-status');
  const queueCard = document.querySelector('#queue-card');
  const queueList = document.querySelector('#queue');
  const dropzone = document.querySelector('#dropzone');
  const pickedList = document.querySelector('#picked');
  const startButton = document.querySelector('#start');
  const declaration = form.querySelector('[name="declaration_accepted"]');

  const text = (selector, value) => { document.querySelector(selector).textContent = value; };
  text('#publish-intro', t('publish.intro'));
  text('#dropzone-title', t('publish.dropTitle'));
  text('#dropzone-hint', t('publish.dropHint'));
  text('#pick-files', t('publish.chooseFiles'));
  text('#pick-folder', t('publish.chooseFolder'));
  text('#options-legend', t('publish.options'));
  text('#opt-libraries', t('publish.optionRemoveLibraries'));
  text('#opt-libraries-hint', t('publish.optionRemoveLibrariesHint'));
  text('#opt-downgrade', t('publish.optionDowngrade'));
  text('#opt-sources', t('publish.optionOtherSources'));
  text('#declaration-text', t('publish.declaration'));
  text('#queue-title', t('publish.queueTitle'));
  text('#queue-hint', t('publish.queueHint'));
  text('#history-title', t('publish.historyTitle'));

  /* ---------- Choosing files ---------- */

  let pending = [];

  const isIpa = (file) => /\.ipa$/i.test(file.name);
  const sameFile = (a, b) => a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;

  function addFiles(files) {
    const ipas = files.filter(isIpa);
    if (!ipas.length) {
      status.replaceChildren(el('div', { className: 'notice' }, el('p', {}, t('publish.noIpa'))));
      return;
    }
    status.replaceChildren();
    for (const file of ipas) {
      if (!pending.some((other) => sameFile(other, file))) pending.push(file);
    }
    renderPending();
  }

  function renderPending() {
    pickedList.hidden = pending.length === 0;
    pickedList.replaceChildren(...pending.map((file) => el('li', {},
      el('span', {}, el('b', {}, file.webkitRelativePath || file.name), ' ', el('span', { className: 'muted' }, formatSize(file.size))),
      el('button', { type: 'button', className: 'button button--ghost', onclick: () => { pending = pending.filter((other) => other !== file); renderPending(); } }, t('publish.remove')),
    )));
    startButton.textContent = pending.length ? t('publish.startCount', { count: pending.length }) : t('publish.start');
    startButton.disabled = pending.length === 0;
  }
  renderPending();

  document.querySelector('#pick-files').addEventListener('click', () => document.querySelector('#input-files').click());
  document.querySelector('#pick-folder').addEventListener('click', () => document.querySelector('#input-folder').click());
  for (const id of ['#input-files', '#input-folder']) {
    const input = document.querySelector(id);
    input.addEventListener('change', () => { addFiles([...input.files]); input.value = ''; });
  }

  // A dropped folder is walked recursively; only .ipa files are taken.
  async function filesOf(entry) {
    if (entry.isFile) return [await new Promise((resolve, reject) => { entry.file(resolve, reject); })];
    const reader = entry.createReader();
    const found = [];
    for (;;) {
      const batch = await new Promise((resolve, reject) => { reader.readEntries(resolve, reject); });
      if (!batch.length) break;
      for (const child of batch) found.push(...await filesOf(child));
    }
    return found;
  }

  ['dragenter', 'dragover'].forEach((type) => dropzone.addEventListener(type, (event) => {
    event.preventDefault();
    dropzone.classList.add('dropzone--over');
  }));
  ['dragleave', 'drop'].forEach((type) => dropzone.addEventListener(type, () => dropzone.classList.remove('dropzone--over')));
  dropzone.addEventListener('drop', async (event) => {
    event.preventDefault();
    const entries = [...(event.dataTransfer?.items ?? [])].map((item) => item.webkitGetAsEntry?.()).filter(Boolean);
    try {
      const files = entries.length ? (await Promise.all(entries.map(filesOf))).flat() : [...event.dataTransfer.files];
      addFiles(files);
    } catch (error) {
      status.replaceChildren(errorNotice(t, error));
    }
  });
  // Dropping on the rest of the page must not make the browser open the file.
  window.addEventListener('dragover', (event) => event.preventDefault());
  window.addEventListener('drop', (event) => event.preventDefault());

  /* ---------- One file ---------- */

  class PublishItem {
    constructor(file, options) {
      Object.assign(this, { file, options, uploadId: null, chunkSize: 0, chunkCount: 0, received: 0, phase: 'queued', job: null, error: null, controller: null, timer: null });
      this.row = el('li', { className: 'upload-row' });
      queueList.append(this.row);
      this.render();
    }

    get percent() {
      return this.chunkCount ? Math.floor((this.received / this.chunkCount) * 100) : 0;
    }

    async upload() {
      this.phase = 'uploading';
      this.error = null;
      this.controller = new AbortController();
      this.render();
      try {
        if (!this.uploadId) {
          const { data } = await api.post('/admin/quick-publish/uploads', {
            filename: this.file.name,
            size_bytes: this.file.size,
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

        this.phase = 'assembling';
        this.render();
        const { data: job } = await api.post(`/admin/quick-publish/uploads/${this.uploadId}/complete`, { options: this.options });
        this.job = job;
        this.phase = 'server';
        this.render();
        this.follow();
      } catch (error) {
        if (error?.name === 'AbortError') {
          this.phase = 'paused';
        } else {
          this.phase = 'failed';
          this.error = error;
          // A rejected upload session cannot be reused; a retry starts a new one.
          if (['UPLOAD_CORRUPT', 'DUPLICATE_ARTIFACT', 'CONFLICT', 'NOT_FOUND'].includes(error.code)) this.uploadId = null;
        }
        this.render();
      }
    }

    /** The server works on its own from here; this only watches. */
    follow() {
      clearTimeout(this.timer);
      this.timer = setTimeout(async () => {
        try {
          const { data } = await api.get(`/admin/quick-publish/${this.job.id}`);
          this.job = data;
          this.render();
          if (data.finished) {
            refreshHistory();
            return;
          }
        } catch (error) {
          this.error = error;
          this.render();
        }
        this.follow();
      }, POLL_MS);
    }

    async hold(options) {
      try {
        const { data } = await api.post(`/admin/quick-publish/${this.job.id}/resume`, { options });
        this.job = data;
        this.error = null;
        this.render();
        this.follow();
      } catch (error) {
        this.error = error;
        this.render();
      }
    }

    pause() {
      this.controller?.abort();
    }

    render() {
      const children = [el('div', { className: 'upload-row__head' }, el('b', {}, this.file.name), el('span', { className: 'muted' }, formatSize(this.file.size)))];
      children.push(stagesList(this.job, this.phase));

      if (this.phase === 'uploading') {
        const bar = el('progress', { max: 100, value: this.percent });
        bar.setAttribute('aria-label', this.file.name);
        children.push(bar);
      }

      const foot = el('div', { className: 'upload-row__foot' });
      const state = el('span', { className: 'upload-row__status' });
      const actions = el('span', { className: 'button-row' });
      const message = {
        queued: t('publish.queued'),
        uploading: t('publish.uploading', { percent: this.percent }),
        paused: t('publish.uploading', { percent: this.percent }),
        assembling: t('publish.assembling'),
        failed: t('publish.uploadFailed'),
      }[this.phase];
      if (this.job) {
        state.append(statusBadge(this.job.stage, stageLabel(this.job.stage)), ' ', this.job.message ?? '');
      } else if (message) {
        state.append(message);
      }
      if (this.error) {
        state.append(' ', el('span', { className: 'badge badge--error' }, t.error(this.error)),
          this.error.details?.artifact_id ? el('span', { className: 'mono' }, ` → ${this.error.details.artifact_id}`) : '',
          this.error.requestId ? el('span', { className: 'mono muted' }, ` · ${this.error.requestId}`) : '');
      }

      if (this.phase === 'uploading') actions.append(el('button', { type: 'button', className: 'button', onclick: () => this.pause() }, t('publish.pause')));
      if (this.phase === 'paused') actions.append(el('button', { type: 'button', className: 'button', onclick: () => schedule(this) }, t('publish.resume')));
      if (this.phase === 'failed') actions.append(el('button', { type: 'button', className: 'button', onclick: () => schedule(this) }, t('publish.retry')));
      if (this.job?.stage === 'HELD' && this.job.hold_code === 'DOWNGRADE') {
        actions.append(el('button', { type: 'button', className: 'button', onclick: () => this.hold({ allow_downgrade: true }) }, t('publish.allowDowngrade')));
      }
      if (this.job?.stage === 'HELD' && this.job.hold_code === 'OTHER_SOURCE') {
        actions.append(el('button', { type: 'button', className: 'button', onclick: () => this.hold({ allow_other_sources: true }) }, t('publish.allowOtherSources')));
      }
      if (this.job?.stage === 'HELD' && ['REVIEW', 'CLEANING'].includes(this.job.hold_code)) {
        actions.append(el('a', { className: 'button', href: 'artifacts.html' }, t('publish.openArtifacts')));
      }
      if (this.job?.stage === 'PUBLISHED') actions.append(el('a', { className: 'button', href: 'apps.html' }, t('publish.openCatalog')));

      foot.append(state, actions);
      children.push(foot);
      if (this.job?.result) children.push(resultFacts(this.job.result));
      this.row.replaceChildren(...children);
    }
  }

  // The tracker: finished steps, the current one, and where a stopped file stopped.
  function stagesList(job, phase) {
    let reached = 0;
    let stage = null;
    if (job) {
      stage = job.stage;
      reached = job.stage === 'PUBLISHED' ? 4 : Math.max(0, ...(job.steps ?? []).map((step) => STEP_OF[step.stage] ?? 0), STEP_OF[stage] ?? 0);
    }
    const items = STEPS.map((label, index) => {
      let state = 'idle';
      if (index < reached || stage === 'PUBLISHED') state = 'done';
      else if (index === reached && STOPPED.has(stage)) state = 'stopped';
      else if (index === reached && FAILED.has(stage)) state = 'failed';
      else if (index === reached && (job || phase !== 'queued')) state = 'active';
      const item = el('li', {}, label);
      item.dataset.state = state;
      return item;
    });
    return el('ol', { className: 'stages' }, ...items);
  }

  function resultFacts(result) {
    const facts = [
      el('span', {}, el('b', {}, result.name), ' · ', result.version, result.build_number ? ` (${result.build_number})` : ''),
      el('span', {}, t(result.action === 'created' ? 'publish.created' : 'publish.updated')),
    ];
    if (result.removed_libraries?.length) facts.push(el('span', {}, t('publish.removed', { list: result.removed_libraries.join(', ') })));
    if (result.superseded?.length) facts.push(el('span', {}, t('publish.superseded')));
    if (result.action === 'created') {
      facts.push(el('span', {}, result.category && result.category !== 'Импортировано' ? t('publish.category', { name: result.category }) : t('publish.needsCategory')));
    }
    return el('p', { className: 'publish-facts' }, ...facts);
  }

  /* ---------- A small pool: at most PARALLEL_FILES uploads at once ---------- */

  const waiting = [];
  let active = 0;

  function schedule(item) {
    item.phase = 'queued';
    item.render();
    waiting.push(item);
    pump();
  }

  function pump() {
    while (active < PARALLEL_FILES && waiting.length) {
      const item = waiting.shift();
      active += 1;
      item.upload().finally(() => {
        active -= 1;
        pump();
      });
    }
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    status.replaceChildren();
    if (!pending.length) return;
    if (!declaration.checked) {
      status.replaceChildren(el('div', { className: 'notice notice--error' }, el('p', {}, t('publish.declarationRequired'))));
      return;
    }
    const options = Object.fromEntries(['remove_unknown_libraries', 'allow_downgrade', 'allow_other_sources'].map((name) => [name, form.querySelector(`[name="${name}"]`).checked]));
    queueCard.hidden = false;
    for (const file of pending) schedule(new PublishItem(file, options));
    pending = [];
    renderPending();
  });

  // Leaving the page would abort running uploads; the server-side steps carry on without it.
  window.addEventListener('beforeunload', (event) => {
    if (active > 0) event.preventDefault();
  });

  /* ---------- History ---------- */

  const history = document.querySelector('#history');
  let historyTimer = null;

  async function refreshHistory() {
    clearTimeout(historyTimer);
    try {
      const { data } = await api.get('/admin/quick-publish');
      if (!data.length) {
        history.replaceChildren(el('p', { className: 'muted' }, t('publish.historyEmpty')));
      } else {
        const rows = data.map((job) => el('tr', {},
          el('td', {}, formatDateTime(job.created_at)),
          el('td', {}, el('b', {}, job.filename ?? '—')),
          el('td', {}, statusBadge(job.stage, stageLabel(job.stage))),
          el('td', { className: 'wrap' }, job.message ?? '—'),
        ));
        history.replaceChildren(el('div', { className: 'data-table' }, el('div', { className: 'data-table__scroll' }, el('table', {},
          el('thead', {}, el('tr', {}, ...['when', 'file', 'state', 'message'].map((key) => el('th', {}, t(`publish.${key}`))))),
          el('tbody', {}, ...rows)))));
      }
      // A run that was started before this page was opened keeps updating.
      if (data.some((job) => !job.finished)) historyTimer = setTimeout(refreshHistory, 5000);
    } catch (error) {
      history.replaceChildren(errorNotice(t, error, refreshHistory));
    }
  }
  refreshHistory();
}

main();
