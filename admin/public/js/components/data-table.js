import { errorNotice } from '../app.js';

/**
 * Reusable operator table: search, sortable columns, pagination, and explicit
 * loading / empty / error states.
 *
 * fetchPage({ page, perPage, query, filters, signal }) must resolve to
 *   { rows: object[], pagination: { page, per_page, total, last_page } }.
 * Sorting applies to the loaded page until admin list endpoints accept a sort parameter.
 *
 * columns: [{ key, label, sortable?, render?(row) => Node|string, className? }]
 * filters: [{ name, label, type: 'select' | 'text', options?: [[value, label], ...] }]
 */
export function createDataTable({ container, t, caption, columns, fetchPage, perPage = 20, searchable = true, filters = [], searchLabel }) {
  const state = { page: 1, query: '', filters: {}, sortKey: null, sortDir: 'ascending', rows: [], controller: null };

  container.classList.add('data-table');
  container.innerHTML = `
    <div class="data-table__toolbar"${searchable || filters.length ? '' : ' hidden'}>
      <label class="visually-hidden" for="${container.id}-search"></label>
      <input type="search" id="${container.id}-search"${searchable ? '' : ' hidden'}>
    </div>
    <div class="data-table__scroll">
      <table>
        <caption class="visually-hidden"></caption>
        <thead><tr></tr></thead>
        <tbody></tbody>
      </table>
    </div>
    <div class="data-table__state" role="status" aria-live="polite"></div>
    <div class="data-table__footer">
      <span class="data-table__summary"></span>
      <span class="data-table__pager">
        <button class="button" type="button" data-page="prev">${t('table.previous')}</button>
        <button class="button" type="button" data-page="next">${t('table.next')}</button>
      </span>
    </div>`;

  container.querySelector('caption').textContent = caption;
  const searchInput = container.querySelector('input[type="search"]');
  searchInput.placeholder = searchLabel ?? t('table.search');
  container.querySelector(`label[for="${container.id}-search"]`).textContent = searchLabel ?? t('table.search');

  const toolbar = container.querySelector('.data-table__toolbar');
  for (const filter of filters) {
    const id = `${container.id}-filter-${filter.name}`;
    const label = document.createElement('label');
    label.className = 'visually-hidden';
    label.htmlFor = id;
    label.textContent = filter.label;

    let control;
    if (filter.type === 'select') {
      control = document.createElement('select');
      for (const [value, text] of filter.options) control.append(new Option(text, value));
      control.addEventListener('change', () => applyFilter(filter.name, control.value));
    } else {
      control = document.createElement('input');
      control.type = 'search';
      control.placeholder = filter.label;
      control.addEventListener('input', () => debounced(() => applyFilter(filter.name, control.value.trim())));
    }
    control.id = id;
    toolbar.append(label, control);
  }

  function applyFilter(name, value) {
    if (value) state.filters[name] = value;
    else delete state.filters[name];
    state.page = 1;
    load();
  }
  const headRow = container.querySelector('thead tr');
  const body = container.querySelector('tbody');
  const stateBox = container.querySelector('.data-table__state');
  const summary = container.querySelector('.data-table__summary');
  const prev = container.querySelector('[data-page="prev"]');
  const next = container.querySelector('[data-page="next"]');

  for (const column of columns) {
    const th = document.createElement('th');
    th.scope = 'col';
    if (column.sortable) {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = column.label;
      button.addEventListener('click', () => sortBy(column.key, th));
      th.append(button);
      th.setAttribute('aria-sort', 'none');
    } else {
      th.textContent = column.label;
    }
    headRow.append(th);
  }

  function sortBy(key, th) {
    state.sortDir = state.sortKey === key && state.sortDir === 'ascending' ? 'descending' : 'ascending';
    state.sortKey = key;
    headRow.querySelectorAll('th[aria-sort]').forEach((cell) => cell.setAttribute('aria-sort', 'none'));
    th.setAttribute('aria-sort', state.sortDir);
    renderRows();
  }

  function sortedRows() {
    if (!state.sortKey) return state.rows;
    const factor = state.sortDir === 'ascending' ? 1 : -1;
    return [...state.rows].sort((a, b) =>
      String(a[state.sortKey] ?? '').localeCompare(String(b[state.sortKey] ?? ''), 'ru', { numeric: true }) * factor);
  }

  function renderRows() {
    body.replaceChildren(...sortedRows().map((row) => {
      const tr = document.createElement('tr');
      for (const column of columns) {
        const td = document.createElement('td');
        if (column.className) td.className = column.className;
        const value = column.render ? column.render(row) : row[column.key];
        td.append(value instanceof Node ? value : document.createTextNode(value ?? t('common.none')));
        tr.append(td);
      }
      return tr;
    }));
  }

  async function load() {
    state.controller?.abort();
    state.controller = new AbortController();
    stateBox.textContent = t('common.loading');
    stateBox.hidden = false;
    prev.disabled = next.disabled = true;

    try {
      const { rows, pagination } = await fetchPage({
        page: state.page,
        perPage,
        query: state.query,
        filters: { ...state.filters },
        signal: state.controller.signal,
      });
      state.rows = rows;
      renderRows();
      stateBox.hidden = rows.length > 0;
      stateBox.textContent = rows.length ? '' : t('table.empty');
      summary.textContent = t('table.page', { page: pagination.page, last: pagination.last_page, total: pagination.total });
      prev.disabled = pagination.page <= 1;
      next.disabled = pagination.page >= pagination.last_page;
    } catch (error) {
      if (error?.name === 'AbortError') return;
      body.replaceChildren();
      summary.textContent = '';
      stateBox.replaceChildren(errorNotice(t, error, load));
    }
  }

  let debounce;
  function debounced(run) {
    clearTimeout(debounce);
    debounce = setTimeout(run, 300);
  }
  searchInput.addEventListener('input', (event) => debounced(() => {
    state.query = event.target.value.trim();
    state.page = 1;
    load();
  }));
  prev.addEventListener('click', () => { state.page -= 1; load(); });
  next.addEventListener('click', () => { state.page += 1; load(); });

  load();
  return { reload: load };
}
