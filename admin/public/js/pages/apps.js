import { boot, el, errorNotice, formatDate, formatDateTime, prepareImage, setupTabs } from '../app.js';
import { confirmAction } from '../components/confirm-dialog.js';
import { createDataTable } from '../components/data-table.js';
import { openDialog } from '../components/dialog.js';
import { statusBadge } from '../components/status-badge.js';
import { toast } from '../components/toast.js';

const { api, t, can } = await boot();
const manage = can('catalog.manage');
const SOURCE_TYPES = ['OWN_BUILD', 'PARTNER_BUILD', 'OPEN_SOURCE_BUILD', 'ALTERNATIVE_MARKETPLACE_PACKAGE', 'USER_IMPORT', 'CUSTOMER_PROVIDED'];
const VISIBILITY = ['DRAFT', 'HIDDEN', 'PUBLISHED'];
const AGE_RATINGS = ['4+', '9+', '12+', '17+'];

setupTabs();

let categories = [];
let publishers = [];
async function loadTaxonomy() {
  [{ data: categories }, { data: publishers }] = await Promise.all([api.get('/admin/categories'), api.get('/admin/publishers')]);
}
await loadTaxonomy();

/* ---------- Apps ---------- */

const appsTable = createDataTable({
  container: document.querySelector('#apps-table'),
  t,
  caption: t('apps.tabApps'),
  searchLabel: t('apps.search'),
  perPage: 50,
  filters: [
    { name: 'visibility', label: t('apps.visibility'), type: 'select', options: [['', t('apps.allVisibility')], ...VISIBILITY.map((v) => [v, t(`visibility.${v}`)])] },
    { name: 'category', label: t('apps.category'), type: 'select', options: [['', t('apps.allCategories')], ...categories.map((c) => [c.slug, c.title])] },
    { name: 'deleted', label: t('apps.deleted'), type: 'select', options: [['', t('apps.active')], ['1', t('apps.deleted')]] },
  ],
  columns: [
    { key: 'icon', label: '', render: (app) => (app.icon_url ? el('img', { className: 'thumb', src: app.icon_url, alt: '' }) : el('span', { className: 'thumb thumb--empty' }, app.name.slice(0, 1))) },
    { key: 'name', label: t('apps.name'), sortable: true },
    { key: 'category', label: t('apps.category'), render: (app) => app.category.title },
    { key: 'publisher', label: t('apps.publisher'), render: (app) => app.publisher.name },
    { key: 'visibility', label: t('apps.visibility'), render: (app) => statusBadge(app.visibility === 'PUBLISHED' ? 'ok' : '', t(`visibility.${app.visibility}`)) },
    { key: 'latest_version', label: t('apps.version'), className: 'mono' },
    { key: 'bundle_identifier', label: t('apps.bundle'), className: 'mono' },
    { key: 'has_published_artifact', label: t('apps.installable'), render: (app) => (app.has_published_artifact ? t('common.yes') : t('common.none')) },
    { key: 'actions', label: '', render: (app) => el('button', { type: 'button', className: 'button', onclick: () => openEditor(app.id) }, t('common.open')) },
  ],
  fetchPage: async ({ page, perPage, query, filters, signal }) => {
    const params = new URLSearchParams({ page, per_page: perPage, ...filters });
    if (query) params.set('q', query);
    const { data, meta } = await api.get(`/admin/apps?${params}`, { signal });
    return { rows: data, pagination: meta.pagination };
  },
});

function field(label, control, hint) {
  return el('label', { className: 'field' }, label, control, hint ? el('span', { className: 'hint' }, hint) : null);
}

function select(name, options, value) {
  const node = el('select', { name });
  for (const [optionValue, text] of options) node.append(new Option(text, optionValue, false, optionValue === value));
  return node;
}

/** Listing fields; `app` is null when creating. */
function listingForm(app) {
  const form = el('form', { className: 'stack', noValidate: true },
    el('div', { className: 'form-status' }),
    el('div', { className: 'form-grid' },
      field(t('apps.name'), el('input', { name: 'name', required: true, maxLength: 100, value: app?.name ?? '' })),
      field(t('apps.subtitle'), el('input', { name: 'subtitle', maxLength: 120, value: app?.subtitle ?? '' })),
      field(t('apps.category'), select('category_id', categories.map((c) => [c.id, c.title]), app?.category.id)),
      field(t('apps.publisher'), select('publisher_id', publishers.map((p) => [p.id, p.name]), app?.publisher.id)),
      field(t('apps.sourceType'), select('source_type', SOURCE_TYPES.map((s) => [s, t(`sourceTypes.${s}`)]), app?.source_type)),
      field(t('apps.visibility'), select('visibility', VISIBILITY.map((v) => [v, t(`visibility.${v}`)]), app?.visibility ?? 'DRAFT')),
      field(t('apps.ageRating'), select('age_rating', AGE_RATINGS.map((a) => [a, a]), app?.age_rating ?? '4+')),
      field(t('apps.featuredRank'), el('input', { name: 'featured_rank', type: 'number', min: 1, max: 999, value: app?.featured_rank ?? '' }), t('apps.featuredHint')),
      field(t('apps.bundleId'), el('input', { name: 'bundle_identifier', maxLength: 155, pattern: '[A-Za-z0-9\\-]+(\\.[A-Za-z0-9\\-]+)+', placeholder: 'com.ruappstore.app-name', value: app?.bundle_identifier ?? '' }), t('apps.bundleIdHint')),
      field(t('apps.supportUrl'), el('input', { name: 'support_url', type: 'url', value: app?.support_url ?? '' })),
      field(t('apps.privacyUrl'), el('input', { name: 'privacy_url', type: 'url', value: app?.privacy_url ?? '' }))),
    el('label', { className: 'check' },
      el('input', { name: 'is_storefront', type: 'checkbox', checked: app?.is_storefront ?? false }),
      'Вариант Ru AppStore для команды Apple'),
    field(t('apps.description'), el('textarea', { name: 'description', rows: 5, maxLength: 4000, value: app?.description ?? '' })),
    app ? field(t('apps.reasonOptional'), el('input', { name: 'reason', maxLength: 500 })) : null,
    manage ? el('div', { className: 'button-row' }, el('button', { type: 'submit', className: 'button button--primary' }, t('apps.save'))) : null);

  if (!manage) form.querySelectorAll('input, select, textarea').forEach((control) => { control.disabled = true; });
  return form;
}

function formPayload(form) {
  const data = Object.fromEntries(new FormData(form));
  data.is_storefront = form.querySelector('[name="is_storefront"]').checked;
  data.featured_rank = data.featured_rank ? Number(data.featured_rank) : null;
  for (const key of ['subtitle', 'description', 'bundle_identifier', 'support_url', 'privacy_url', 'reason']) {
    if (key in data && data[key] === '') data[key] = null;
  }
  return data;
}

document.querySelector('#add-app').hidden = !manage;
document.querySelector('#add-app').addEventListener('click', () => {
  const form = listingForm(null);
  const panel = openDialog(t, { title: t('apps.add'), body: form, wide: true });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    try {
      const { data } = await api.post('/admin/apps', formPayload(form));
      panel.close();
      toast(t('apps.created'), { tone: 'ok' });
      appsTable.reload();
      openEditor(data.id);
    } catch (error) {
      form.querySelector('.form-status').replaceChildren(errorNotice(t, error));
    }
  });
});

async function openEditor(id) {
  const panel = openDialog(t, { title: t('common.loading'), body: el('p', {}, t('common.loading')), wide: true, onClose: () => appsTable.reload() });

  async function render() {
    try {
      const { data: app } = await api.get(`/admin/apps/${id}`);
      panel.dialog.querySelector('h2').textContent = app.name;
      panel.setBody(...editor(app, render));
    } catch (error) {
      panel.setBody(errorNotice(t, error, render));
    }
  }

  await render();
}

function editor(app, refresh) {
  const form = listingForm(app);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    try {
      await api.patch(`/admin/apps/${app.id}`, formPayload(form));
      toast(t('apps.saved'), { tone: 'ok' });
      await refresh();
    } catch (error) {
      form.querySelector('.form-status').replaceChildren(errorNotice(t, error));
    }
  });

  const nodes = [
    el('dl', { className: 'details' },
      [[t('apps.bundle'), app.bundle_identifier ?? t('common.none')], ['Slug', app.slug], [t('apps.updated'), formatDateTime(app.updated_at)]]
        .map(([term, value]) => el('div', {}, el('dt', {}, term), el('dd', { className: 'mono' }, value)))),
    form,
    media(app, refresh),
    versions(app, refresh),
  ];

  if (manage) {
    const lifecycle = el('div', { className: 'button-row' });
    if (app.deleted_at) {
      lifecycle.append(el('button', {
        type: 'button', className: 'button', onclick: async () => {
          await act(() => api.post(`/admin/apps/${app.id}/restore`), t('apps.restored'), refresh);
        },
      }, t('apps.restore')));
    } else {
      lifecycle.append(el('button', {
        type: 'button', className: 'button button--danger', onclick: async () => {
          const { confirmed, reason } = await confirmAction(t, { title: t('apps.deleteTitle'), message: t('apps.deleteMessage'), danger: true, requireReason: true });
          if (confirmed) await act(() => api.delete(`/admin/apps/${app.id}`, { body: { reason } }), t('apps.removed'), refresh);
        },
      }, t('apps.deleteApp')));
    }
    nodes.push(lifecycle);
  }

  return nodes;
}

function media(app, refresh) {
  const section = el('section', { className: 'card stack' }, el('h3', {}, t('apps.media')));

  const icon = app.icon_url ? el('img', { className: 'icon-preview', src: app.icon_url, alt: t('apps.icon') }) : el('span', { className: 'icon-preview thumb--empty' }, app.name.slice(0, 1));
  section.append(el('div', { className: 'media-row' }, icon, el('div', {}, el('strong', {}, t('apps.icon')), el('p', { className: 'muted' }, t('apps.iconHint')),
    manage ? upload('image/png,image/jpeg,image/webp', false, async ([file]) => {
      const body = new FormData();
      body.append('icon', file);
      await api.post(`/admin/apps/${app.id}/icon`, body);
    }, refresh) : null)));

  const shots = el('ol', { className: 'shots' });
  app.screenshots.forEach((shot, index) => {
    const move = async (delta) => {
      const order = app.screenshots.map((s) => s.id);
      [order[index], order[index + delta]] = [order[index + delta], order[index]];
      await act(() => api.put(`/admin/apps/${app.id}/screenshots/order`, { order }), null, refresh);
    };
    shots.append(el('li', {}, el('img', { src: shot.url, alt: '', loading: 'lazy' }),
      manage ? el('div', { className: 'button-row' },
        el('button', { type: 'button', className: 'button', disabled: index === 0, onclick: () => move(-1), ariaLabel: t('apps.moveLeft') }, '←'),
        el('button', { type: 'button', className: 'button', disabled: index === app.screenshots.length - 1, onclick: () => move(1), ariaLabel: t('apps.moveRight') }, '→'),
        el('button', { type: 'button', className: 'button button--danger', onclick: () => act(() => api.delete(`/admin/apps/${app.id}/screenshots/${shot.id}`), null, refresh) }, t('apps.remove'))) : null));
  });
  section.append(el('strong', {}, t('apps.screenshots')), el('p', { className: 'muted' }, t('apps.screenshotsHint')), shots,
    manage ? upload('image/png,image/jpeg,image/webp', true, async (files) => {
      for (const file of files) {
        const body = new FormData();
        body.append('screenshot', await prepareImage(file));
        await api.post(`/admin/apps/${app.id}/screenshots`, body);
      }
    }, refresh) : null);

  return section;
}

function upload(accept, multiple, send, refresh) {
  const input = el('input', { type: 'file', accept, multiple });
  const button = el('button', { type: 'button', className: 'button' }, t('apps.upload'));
  button.addEventListener('click', async () => {
    if (!input.files.length) {
      input.click();
      return;
    }
    button.disabled = true;
    try {
      await send([...input.files]);
      await refresh();
    } catch (error) {
      toast(t.error(error), { tone: 'error' });
    } finally {
      button.disabled = false;
    }
  });
  input.addEventListener('change', () => button.click());
  return el('div', { className: 'button-row' }, input, button);
}

function versions(app, refresh) {
  const section = el('section', { className: 'card stack' }, el('h3', {}, t('apps.versions')));

  if (!app.versions.length) section.append(el('p', { className: 'muted' }, t('apps.noVersions')));
  for (const version of app.versions) {
    const notes = el('textarea', { rows: 2, maxLength: 4000, value: version.release_notes ?? '', disabled: !manage });
    const save = el('button', { type: 'button', className: 'button', hidden: !manage }, t('apps.save'));
    save.addEventListener('click', () => act(() => api.patch(`/admin/app-versions/${version.id}`, { release_notes: notes.value || null }), t('apps.saved'), refresh));
    section.append(el('div', { className: 'version-row' },
      el('strong', { className: 'mono' }, `${version.version} (${version.build_number})`),
      el('span', { className: 'muted' }, `${t('apps.minIos')} ${version.min_ios_version ?? '—'} · ${t('apps.released')} ${formatDate(version.released_at)}`),
      field(t('apps.releaseNotes'), notes), save));
  }

  if (manage) {
    const form = el('form', { className: 'form-grid add-version', noValidate: true },
      field('Версия', el('input', { name: 'version', required: true, pattern: '\\d+(\\.\\d+){0,3}', placeholder: '1.0.0' })),
      field(t('apps.build'), el('input', { name: 'build_number', required: true, placeholder: '100' })),
      field(t('apps.minIos'), el('input', { name: 'min_ios_version', placeholder: '17.0' })),
      field(t('apps.releaseNotes'), el('input', { name: 'release_notes' })),
      el('button', { type: 'submit', className: 'button' }, t('apps.addVersion')));
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      const data = Object.fromEntries(new FormData(form));
      for (const key of Object.keys(data)) if (data[key] === '') delete data[key];
      await act(() => api.post(`/admin/apps/${app.id}/versions`, data), t('apps.saved'), refresh);
    });
    section.append(form);
  }

  return section;
}

async function act(request, message, refresh) {
  try {
    await request();
    if (message) toast(message, { tone: 'ok' });
    await refresh();
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
  }
}

/* ---------- Categories and publishers ---------- */

function taxonomyTable(container, columns, rows, onSave) {
  const table = el('table', {}, el('thead', {}, el('tr', {}, columns.map((c) => el('th', { scope: 'col' }, c.label)), el('th', {}))));
  const body = el('tbody');
  for (const row of rows) {
    const inputs = columns.map((c) => {
      if (c.editable && manage && c.options) {
        return el('select', {}, c.options.map(([value, label]) => el('option', { value, selected: row[c.key] === value }, label)));
      }
      if (c.editable && manage) return el('input', { value: row[c.key] ?? '', type: c.type ?? 'text' });
      return document.createTextNode(c.options?.find(([value]) => value === row[c.key])?.[1] ?? row[c.key] ?? '—');
    });
    const save = manage ? el('button', {
      type: 'button', className: 'button', onclick: () => {
        const data = {};
        columns.forEach((c, i) => { if (c.editable) data[c.key] = c.type === 'number' ? Number(inputs[i].value) : (inputs[i].value || null); });
        onSave(row, data);
      },
    }, t('apps.save')) : '';
    body.append(el('tr', {}, inputs.map((input) => el('td', {}, input)), el('td', {}, save)));
  }
  table.append(body);
  container.replaceChildren(el('div', { className: 'data-table' }, el('div', { className: 'data-table__scroll' }, table)));
}

// Which storefront tab a category appears in (Приложения or Игры).
const CATEGORY_KINDS = () => [['APPS', t('apps.kindApps')], ['GAMES', t('apps.kindGames')]];

function renderCategories() {
  const container = document.querySelector('#categories');
  taxonomyTable(container, [
    { key: 'title', label: t('apps.categoryTitle'), editable: true },
    { key: 'subtitle', label: t('apps.categorySubtitle'), editable: true },
    { key: 'kind', label: t('apps.categoryKind'), editable: true, options: CATEGORY_KINDS() },
    { key: 'slug', label: 'Slug' },
    { key: 'sort_order', label: t('apps.sortOrder'), editable: true, type: 'number' },
    { key: 'app_count', label: t('apps.appCount') },
  ], categories, (row, data) => act(() => api.patch(`/admin/categories/${row.id}`, data), t('apps.saved'), reloadTaxonomy));

  if (manage) {
    const form = el('form', { className: 'form-grid', noValidate: true },
      field(t('apps.categoryTitle'), el('input', { name: 'title', required: true, maxLength: 64 })),
      field(t('apps.categorySubtitle'), el('input', { name: 'subtitle', maxLength: 120 })),
      field(t('apps.categoryKind'), el('select', { name: 'kind' }, CATEGORY_KINDS().map(([value, label]) => el('option', { value }, label)))),
      el('button', { type: 'submit', className: 'button button--primary' }, t('apps.addCategory')));
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      const data = Object.fromEntries(new FormData(form));
      if (!data.subtitle) delete data.subtitle;
      act(() => api.post('/admin/categories', data), t('apps.saved'), reloadTaxonomy);
    });
    container.prepend(form);
  }
}

function renderPublishers() {
  const container = document.querySelector('#publishers');
  taxonomyTable(container, [
    { key: 'name', label: t('apps.publisher'), editable: true },
    { key: 'website', label: t('apps.website'), editable: true, type: 'url' },
    { key: 'support_email', label: t('apps.supportEmail'), editable: true, type: 'email' },
    { key: 'app_count', label: t('apps.appCount') },
  ], publishers, (row, data) => act(() => api.patch(`/admin/publishers/${row.id}`, data), t('apps.saved'), reloadTaxonomy));

  if (manage) {
    const form = el('form', { className: 'form-grid', noValidate: true },
      field(t('apps.publisher'), el('input', { name: 'name', required: true, maxLength: 100 })),
      field(t('apps.website'), el('input', { name: 'website', type: 'url' })),
      el('button', { type: 'submit', className: 'button button--primary' }, t('apps.addPublisher')));
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      const data = Object.fromEntries(new FormData(form));
      if (!data.website) delete data.website;
      act(() => api.post('/admin/publishers', data), t('apps.saved'), reloadTaxonomy);
    });
    container.prepend(form);
  }
}

async function reloadTaxonomy() {
  await loadTaxonomy();
  renderCategories();
  renderPublishers();
}

renderCategories();
renderPublishers();
