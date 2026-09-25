import { boot, el, formatDateTime } from '../app.js';
import { createDataTable } from '../components/data-table.js';
import { openDialog } from '../components/dialog.js';

const { api, t } = await boot();

function actorText(actor) {
  if (actor.type === 'user') return actor.label ?? actor.id ?? t('common.none');
  return actor.label && actor.type !== 'system' ? `${t(`audit.${actor.type}`)}: ${actor.label}` : t(`audit.${actor.type}`);
}

function json(value) {
  return el('pre', { className: 'json' }, value === null || value === undefined ? t('common.none') : JSON.stringify(value, null, 2));
}

function showDetails(entry) {
  openDialog(t, {
    title: entry.action,
    wide: true,
    body: [
      el('dl', { className: 'details' },
        [[t('audit.time'), formatDateTime(entry.occurred_at)], [t('audit.actor'), actorText(entry.actor)],
          [t('audit.subject'), entry.subject_type ? `${entry.subject_type} ${entry.subject_id ?? ''}` : t('common.none')],
          [t('audit.reason'), entry.reason ?? t('common.none')], [t('audit.requestId'), entry.request_id ?? t('common.none')],
          [t('audit.ip'), entry.ip ?? t('common.none')]]
          .map(([term, value]) => el('div', {}, el('dt', {}, term), el('dd', {}, value)))),
      el('h3', {}, t('audit.before')), json(entry.before),
      el('h3', {}, t('audit.after')), json(entry.after),
    ],
  });
}

createDataTable({
  container: document.querySelector('#audit-table'),
  t,
  caption: t('audit.action'),
  perPage: 50,
  searchable: false,
  filters: [
    { name: 'action', label: t('audit.actionFilter'), type: 'text' },
    { name: 'request_id', label: t('audit.requestId'), type: 'text' },
  ],
  columns: [
    { key: 'occurred_at', label: t('audit.time'), render: (entry) => formatDateTime(entry.occurred_at) },
    { key: 'actor', label: t('audit.actor'), render: (entry) => actorText(entry.actor) },
    { key: 'action', label: t('audit.action'), className: 'mono' },
    { key: 'subject', label: t('audit.subject'), render: (entry) => (entry.subject_type ? `${entry.subject_type} ${entry.subject_id?.slice(-6) ?? ''}` : t('common.none')) },
    { key: 'request_id', label: t('audit.requestId'), className: 'mono', render: (entry) => entry.request_id?.slice(0, 12) ?? t('common.none') },
    { key: 'details', label: '', render: (entry) => el('button', { type: 'button', className: 'button', onclick: () => showDetails(entry) }, t('audit.details')) },
  ],
  fetchPage: async ({ page, perPage, filters, signal }) => {
    const params = new URLSearchParams({ page, per_page: perPage, ...filters });
    const { data, meta } = await api.get(`/admin/audit-logs?${params}`, { signal });
    return { rows: data, pagination: meta.pagination };
  },
});
