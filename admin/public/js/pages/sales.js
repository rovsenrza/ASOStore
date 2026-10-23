import { boot, el, errorNotice } from '../app.js';

/**
 * Repeat-purchase analytics over paid store orders (bot + website): how many buyers come back,
 * how many orders and how much revenue are repeat vs first purchases.
 */
const { api, t } = await boot();
const box = document.querySelector('#sales');

const rub = (v) => `${Number(v || 0).toLocaleString('ru-RU')} ₽`;
const pct = (v) => `${Number(v || 0).toLocaleString('ru-RU')}%`;

function stat(label, value, hint) {
  return el('div', { className: 'stat-card' },
    el('span', { className: 'stat-label' }, label),
    el('strong', { className: 'stat-value' }, String(value)),
    hint ? el('span', { className: 'muted' }, hint) : el('span'));
}

function section(title, s) {
  const channels = Object.entries(s.by_channel || {}).map(([name, c]) =>
    el('li', {}, `${name === 'web' ? 'Сайт' : name === 'telegram' ? 'Бот' : name}: ${c.paid_orders} заказ(ов), ${rub(c.revenue_rub)}, покупателей ${c.unique_buyers} (повторных ${c.repeat_buyers})`));

  return el('section', { className: 'stack' },
    el('h2', {}, title),
    el('div', { className: 'stat-grid' },
      stat('Оплаченные заказы', s.paid_orders, rub(s.revenue_rub) + ' выручки'),
      stat('Покупателей', s.unique_buyers, `${s.first_orders} первых покупок`),
      stat('Повторные покупатели', s.repeat_buyers, `${pct(s.repeat_buyer_rate)} из всех`),
      stat('Повторные заказы', s.repeat_orders, `${rub(s.repeat_revenue_rub)} · ${pct(s.repeat_revenue_rate)} выручки`),
    ),
    channels.length ? el('ul', { className: 'plain-list' }, ...channels) : el('p', { className: 'muted' }, 'Пока нет оплаченных заказов.'),
  );
}

try {
  const { data } = await api.get('/admin/purchases');
  box.replaceChildren(
    el('p', { className: 'muted' }, 'Повторная покупка — когда один покупатель оплатил заказ не первый раз. Тестовые (mock) платежи не учитываются.'),
    section('За последние 30 дней', data.window),
    el('hr'),
    section('За всё время', data.all_time),
  );
} catch (error) {
  box.replaceChildren(errorNotice(t, error, () => location.reload()));
}
