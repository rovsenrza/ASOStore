import { boot, renderError } from '../app.js';

/**
 * Where Platega sends the payer back (?order=…, plus &failed=1 when the payment failed).
 * The order is read until the payment settles: the server asks Platega itself when the
 * callback is late, so this page ends on «оплачено» without the payer doing anything.
 */
const { api, t } = boot();
const params = new URLSearchParams(location.search);
const orderId = params.get('order') ?? '';
const failed = params.get('failed') === '1';
const state = document.querySelector('#payment-state');
const actions = document.querySelector('#payment-actions');
const lead = document.querySelector('#payment-lead');
const rub = (value) => `${value.toLocaleString('ru-RU')} ₽`;

const FAST_POLLS = 40; // every 3 s for two minutes, then every 15 s
let polls = 0;

function notice(tone, title, text) {
  const block = document.createElement('div');
  block.className = tone;
  block.setAttribute('role', tone.includes('error') ? 'alert' : 'status');
  const heading = document.createElement('b');
  heading.textContent = title;
  block.append(heading);
  for (const line of [].concat(text)) {
    const paragraph = document.createElement('p');
    paragraph.textContent = line;
    block.append(paragraph);
  }
  return block;
}

function link(label, href, primary = false) {
  const anchor = document.createElement('a');
  anchor.className = primary ? 'btn btn--primary' : 'btn btn--line';
  anchor.href = href;
  anchor.textContent = label;
  return anchor;
}

function waiting(order) {
  const line = document.createElement('p');
  line.className = 'loading-line';
  line.innerHTML = '<span class="status-pulse" aria-hidden="true"></span>';
  line.append('Ждём подтверждения от платёжной системы…');
  return [
    notice('notice', 'Платёж обрабатывается', [
      'Обычно это занимает до минуты. Страница обновится сама, её можно не перезагружать.',
      order.channel === 'web'
        ? 'Если вы закроете страницу, доступ всё равно включится в аккаунте после оплаты.'
        : 'Если вы закроете страницу, код активации всё равно придёт в Telegram-бот после оплаты.',
    ]),
    line,
  ];
}

function retryHref(order) {
  if (order.channel === 'telegram') return order.bot_url ?? '/buy.html';
  return `/buy.html?plan=${encodeURIComponent(order.plan ?? '')}#pricing`;
}

function render(order) {
  const summary = `Заказ #${order.reference} · доступ на ${order.term} · ${rub(order.amount)}`;
  let blocks;
  let buttons = [];
  let done = true;

  switch (order.status) {
    case 'PAID':
      lead.textContent = 'Оплата получена. Спасибо!';
      if (order.channel === 'web') {
        blocks = [notice('notice notice--ok', 'Доступ включён', [
          summary,
          'Подписка уже действует в вашем аккаунте, код вводить не нужно. Осталось зарегистрировать iPhone.',
        ])];
        buttons = [link('Зарегистрировать iPhone', '/activate.html', true), link('Каталог приложений', '/')];
      } else {
        blocks = [notice('notice notice--ok', 'Код активации отправлен в Telegram', [
          summary,
          'Откройте бот: код уже в чате и в разделе «Профиль → Мои заказы». Введите его на странице регистрации iPhone.',
        ])];
        buttons = [order.bot_url && link('Открыть бот', order.bot_url, true), link('Ввести код', '/activate.html', !order.bot_url)].filter(Boolean);
      }
      break;
    case 'REVIEW':
      lead.textContent = 'Проверяем оплату.';
      blocks = [notice('notice', 'Платёж на проверке', [summary, 'Мы сверяем платёж вручную. Как только он подтвердится, доступ включится — обычно это занимает несколько минут.'])];
      done = false;
      break;
    case 'PENDING':
      if (failed) {
        lead.textContent = 'Оплата не прошла.';
        blocks = [notice('notice notice--error', 'Платёж не завершён', [summary, 'Деньги не списаны. Попробуйте оплатить ещё раз — можно выбрать другой способ оплаты.'])];
        buttons = [link('Попробовать снова', retryHref(order), true)];
        // A payment that went through after all still shows up here.
        done = false;
      } else {
        lead.textContent = 'Проверяем статус оплаты.';
        blocks = waiting(order);
        done = false;
      }
      break;
    default:
      lead.textContent = 'Заказ закрыт.';
      blocks = [notice('notice notice--error', 'Заказ закрыт без оплаты', [summary, 'Время на оплату истекло или заказ отменён. Если деньги списались, напишите в поддержку — мы разберёмся.'])];
      buttons = [link('Оформить новый заказ', retryHref(order), true)];
  }

  state.replaceChildren(...blocks);
  actions.replaceChildren(...buttons);
  return done;
}

async function load() {
  try {
    const { data } = await api.get(`/store/orders/${encodeURIComponent(orderId)}`);
    if (render(data.order)) return;
  } catch (error) {
    if (error?.code === 'NOT_FOUND') {
      lead.textContent = 'Заказ не найден.';
      state.replaceChildren(notice('notice notice--error', 'Такого заказа нет', 'Проверьте ссылку или оформите заказ заново.'));
      actions.replaceChildren(link('Купить доступ', '/buy.html', true));
      return;
    }
    // Keep polling through network hiccups; show the error only before anything rendered.
    if (polls === 0) renderError(state, t, error, () => load());
  }
  polls += 1;
  setTimeout(load, polls < FAST_POLLS ? 3000 : 15000);
}

if (/^[0-9a-zA-Z]{26}$/.test(orderId)) {
  load();
} else {
  lead.textContent = 'Заказ не найден.';
  state.replaceChildren(notice('notice notice--error', 'В ссылке нет номера заказа', 'Откройте страницу по ссылке после оплаты или оформите заказ заново.'));
  actions.replaceChildren(link('Купить доступ', '/buy.html', true));
}
