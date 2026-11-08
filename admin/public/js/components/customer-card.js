import { el, errorNotice, formatDate, formatDateTime, newIdempotencyKey } from '../app.js';
import { confirmAction } from './confirm-dialog.js';
import { openDialog } from './dialog.js';
import { statusBadge } from './status-badge.js';
import { toast } from './toast.js';

/**
 * Customer card: everything about one account on one screen, with the actions support uses
 * every day (change the end of access, write in Telegram, enable or disable a registration).
 * Opens from any page: openCustomerCard(ctx, userPublicId).
 */

const REG_STATUS = {
  ENROLLED: 'Ждёт регистрации', APPLE_PENDING: 'Ждёт Apple', ELIGIBLE: 'Работает', APPLE_FAILED: 'Ошибка Apple',
  QUOTA_BLOCKED: 'Нет мест', NO_ELIGIBLE_TEAM: 'Нет команды', DISABLED: 'Отключена',
};
const ORDER_STATUS = { PENDING: 'Ждёт оплаты', REVIEW: 'На проверке', PAID: 'Оплачен', CANCELLED: 'Отменён', EXPIRED: 'Истёк', REJECTED: 'Отклонён' };

export const regStatusLabel = (status) => REG_STATUS[status] ?? status;
export const orderStatusLabel = (status) => ORDER_STATUS[status] ?? status;
export const rub = (value) => `${Number(value || 0).toLocaleString('ru-RU')} ₽`;

export function hoursText(hours) {
  if (hours === null || hours === undefined) return '—';
  if (hours < 1) return `${Math.round(hours * 60)} мин`;
  if (hours < 48) return `${hours.toFixed(1).replace('.', ',')} ч`;
  return `${(hours / 24).toFixed(1).replace('.', ',')} дн`;
}

export function telegramLink(contact) {
  if (!contact) return '—';
  const label = contact.username ? `@${contact.username}` : (contact.first_name || String(contact.telegram_user_id ?? contact.id));
  const href = contact.username ? `https://t.me/${contact.username}` : `tg://user?id=${contact.telegram_user_id ?? contact.id}`;
  return el('a', { href, target: '_blank', rel: 'noopener' }, label);
}

function table(headers, rows, empty = 'Нет данных.') {
  if (!rows.length) return el('p', { className: 'muted' }, empty);
  return el('div', { className: 'simple-table' }, el('table', {},
    el('thead', {}, el('tr', {}, headers.map((h) => el('th', { scope: 'col' }, h)))),
    el('tbody', {}, rows.map((cells) => el('tr', {}, cells.map((c) => el('td', {}, c ?? '—')))))));
}

function section(title, ...children) {
  return el('section', { className: 'card-section' }, el('h3', {}, title), ...children);
}

export async function openCustomerCard(ctx, userId, { onChange } = {}) {
  const { api, can } = ctx;
  const panel = openDialog(ctx.t, { title: 'Загрузка…', body: el('p', {}, 'Загрузка…'), wide: true, onClose: onChange });

  async function render() {
    try {
      const { data } = await api.get(`/admin/users/${userId}/profile`);
      panel.dialog.querySelector('h2').textContent = `${data.name || data.email} · №${data.number}`;
      panel.setBody(...build(data));
    } catch (error) {
      panel.setBody(errorNotice(ctx.t, error, render));
    }
  }

  async function act(run, ok) {
    try {
      await run();
      toast(ok, { tone: 'ok' });
      await render();
    } catch (error) {
      toast(ctx.t.error(error), { tone: 'error' });
    }
  }

  function build(user) {
    const access = user.access;
    const accessText = access.unlimited ? 'Бессрочно'
      : access.ends_at ? `${formatDateTime(access.ends_at)} · ${access.days_left > 0 ? `осталось ${String(access.days_left).replace('.', ',')} дн` : 'закончилась'}` : 'Нет подписки';

    const copyId = el('button', { type: 'button', className: 'button button--ghost', onclick: async () => {
      await navigator.clipboard.writeText(`${user.email} · №${user.number}`);
      toast('Скопировано', { tone: 'ok' });
    } }, 'Копировать');

    const summary = el('dl', { className: 'details' }, [
      ['Email', el('span', {}, user.email, ' ', copyId)],
      ['Номер', String(user.number)],
      ['Статус', statusBadge(user.status, user.status === 'ACTIVE' ? 'Активен' : 'Заблокирован')],
      ['Доступ', el('span', {}, statusBadge(access.active ? 'ACTIVE' : 'EXPIRED', access.active ? 'Есть' : 'Нет'), ' ', accessText)],
      ['Оплачено дней (коды)', String(access.paid_days)],
      ['Telegram', user.telegram.length ? el('span', {}, ...user.telegram.flatMap((c, i) => [i ? ', ' : '', telegramLink(c),
        c.balance_rub ? ` (баланс ${rub(c.balance_rub)})` : '', c.blocked_bot ? ' · заблокировал бота' : ''])) : 'не найден (покупал не через бота)'],
      ['Зарегистрирован', formatDateTime(user.created_at)],
    ].map(([term, value]) => el('div', {}, el('dt', {}, term), el('dd', {}, value))));

    const nodes = [summary];
    if (can('users.manage')) nodes.push(subscriptionForm(user));
    if (can('users.manage') && user.telegram.length) nodes.push(messageForm(user));

    nodes.push(section(`Устройства (${user.devices.length})`, user.devices.length ? el('div', { className: 'stack' }, user.devices.map((device) => el('div', { className: 'device-block' },
      el('p', {}, el('strong', {}, device.product || device.name || 'Устройство'), ` · ${device.udid_hint} · iOS ${device.os_version ?? '?'} · подключено ${formatDateTime(device.enrolled_at)}`),
      table(['Команда', 'Статус', 'Причина', 'Создана', 'Готова', 'Ожидание', 'Компенсация', ''],
        device.registrations.map((r) => [
          el('span', { className: 'mono', title: r.team_name ?? '' }, r.team ?? '—', r.team_status && r.team_status !== 'ACTIVE' ? ` (${r.team_status})` : ''),
          statusBadge(r.status, regStatusLabel(r.status)),
          el('span', { className: 'mono' }, r.reason ?? ''),
          formatDateTime(r.created_at),
          formatDateTime(r.eligible_at),
          hoursText(r.waited_hours),
          r.compensation_minutes ? hoursText(r.compensation_minutes / 60) : (r.gift_at ? 'подарок' : '—'),
          registrationActions(r),
        ]))))) : el('p', { className: 'muted' }, 'Устройств нет.')));

    nodes.push(section('Подписки', table(['План', 'Статус', 'Начало', 'Конец'],
      user.subscriptions.map((s) => [s.plan, statusBadge(s.status, s.status), formatDateTime(s.starts_at), s.ends_at ? formatDateTime(s.ends_at) : 'бессрочно']), 'Подписок нет.')));

    nodes.push(section('Изменения срока', table(['Когда', 'Кто', 'Было', 'Стало', 'Причина'],
      user.extensions.map((e) => [formatDateTime(e.occurred_at), e.actor ?? '—', formatDateTime(e.before), formatDateTime(e.after), e.reason ?? '']), 'Срок не менялся.')));

    nodes.push(section(`Заказы (${user.orders.length})`, table(['Заказ', 'Статус', 'Канал', 'Тариф', 'Сумма', 'Промокод', 'Создан', 'Оплачен'],
      user.orders.map((o) => [el('span', { className: 'mono', title: o.reference }, o.short), statusBadge(o.status === 'PAID' ? 'ok' : o.status, orderStatusLabel(o.status)),
        o.channel === 'web' ? 'Сайт' : 'Бот', `${o.duration_days} дн${o.renewal ? ' · продление' : ''}`, rub(o.amount_due_rub), o.promo ?? '', formatDateTime(o.created_at), formatDateTime(o.paid_at)]), 'Заказов нет.')));

    nodes.push(section('Коды активации', table(['Код', 'Статус', 'Срок', 'Заметка', 'Активирован'],
      user.codes.map((c) => [el('span', { className: 'mono' }, `••••-${c.hint}`), c.status, c.duration_days ? `${c.duration_days} дн` : 'бессрочно', c.note ?? '', formatDateTime(c.redeemed_at)]), 'Кодов нет.')));

    nodes.push(section('Установки (последние 20)', table(['Приложение', 'Статус', 'Причина', 'Начало', 'Доставлено'],
      user.installations.map((i) => [i.app ?? '—', statusBadge(i.status, i.status), el('span', { className: 'mono' }, i.reason ?? ''), formatDateTime(i.created_at), formatDateTime(i.delivered_at)]), 'Установок нет.')));

    if (user.tickets.length) {
      nodes.push(section('Обращения', table(['Когда', 'Тема', 'Статус', 'Текст'],
        user.tickets.map((tk) => [formatDateTime(tk.created_at), tk.topic, tk.status, tk.message.slice(0, 200)]))));
    }
    return nodes;
  }

  function registrationActions(r) {
    if (!can('devices.manage')) return '';
    const row = el('span', { className: 'button-row button-row--tight' });
    const ask = async (path, title, message, ok, danger = false) => {
      const { confirmed, reason } = await confirmAction(ctx.t, { title, message, danger, requireReason: true });
      if (!confirmed) return;
      await act(() => api.post(`/admin/device-registrations/${r.id}/${path}`, { reason }), ok);
    };
    if (r.status === 'DISABLED' && r.has_apple_slot) {
      row.append(el('button', { type: 'button', className: 'button', onclick: () => ask('enable', 'Включить регистрацию?',
        'Регистрация снова станет рабочей, если Apple держит устройство включённым. Новое место Apple не занимается, компенсация за ожидание не начисляется.', 'Регистрация включена') }, 'Включить'));
    }
    if (r.status === 'ELIGIBLE') {
      row.append(el('button', { type: 'button', className: 'button button--danger', onclick: () => ask('disable', 'Отключить регистрацию?',
        'Устройство перестанет получать сборки с этой команды. Место в Apple останется занятым до конца года.', 'Регистрация отключена', true) }, 'Отключить'));
    }
    if (['APPLE_PENDING', 'APPLE_FAILED', 'QUOTA_BLOCKED', 'NO_ELIGIBLE_TEAM', 'ENROLLED'].includes(r.status)) {
      row.append(el('button', { type: 'button', className: 'button', onclick: () => act(() => api.post(`/admin/device-registrations/${r.id}/retry`, {}), 'Запрошено у Apple') },
        r.status === 'APPLE_PENDING' ? 'Проверить в Apple' : 'Повторить'));
    }
    return row;
  }

  function subscriptionForm(user) {
    const days = el('input', { type: 'number', step: '0.5', min: '-3650', max: '3650', value: '3', name: 'days', className: 'input-short' });
    const date = el('input', { type: 'datetime-local', name: 'ends_at' });
    if (user.access.ends_at) date.value = toLocalInput(user.access.ends_at);
    const reason = el('input', { name: 'reason', placeholder: 'Причина (в журнал аудита)', maxLength: 500, required: true });
    const quick = [1, 3, 7, 30, -1, -3, -7].map((n) => el('button', { type: 'button', className: 'button button--ghost', onclick: () => { days.value = String(n); } }, n > 0 ? `+${n}` : String(n)));

    const send = async (body) => {
      if (!reason.value.trim()) {
        reason.reportValidity();
        toast('Укажите причину', { tone: 'error' });
        return;
      }
      await act(() => api.post(`/admin/users/${user.id}/subscription`, { ...body, reason: reason.value.trim() }, { idempotencyKey: newIdempotencyKey('sub') }),
        'Срок доступа изменён');
    };

    return el('section', { className: 'card actions-card' },
      el('h3', {}, 'Срок доступа'),
      el('div', { className: 'form-inline' },
        el('label', { className: 'field' }, 'Дней (минус — убрать)', days),
        el('div', { className: 'button-row' }, ...quick),
        el('button', { type: 'button', className: 'button button--primary', onclick: () => send({ mode: 'add', days: Number(days.value) }) }, 'Добавить / убрать дни')),
      el('div', { className: 'form-inline' },
        el('label', { className: 'field' }, 'Или точная дата окончания', date),
        el('button', { type: 'button', className: 'button', onclick: () => date.value && send({ mode: 'set', ends_at: new Date(date.value).toISOString() }) }, 'Установить дату')),
      el('label', { className: 'field' }, 'Причина', reason));
  }

  function messageForm(user) {
    const text = el('textarea', { rows: 3, maxLength: 3500, placeholder: 'Текст сообщения покупателю в Telegram от бота магазина' });
    const templates = [
      ['Аккаунт открыт', 'Здравствуйте! Ваше устройство подключено, можно устанавливать приложения. Откройте ruappstore.com на iPhone.'],
      ['Ещё ждём Apple', 'Здравствуйте! Apple ещё обрабатывает ваше устройство (обычно до 72 часов). Время ожидания мы добавим к подписке.'],
      ['Подписка заканчивается', 'Здравствуйте! Ваша подписка скоро закончится. Продлить можно в боте кнопкой «🔄 Продлить подписку».'],
    ].map(([label, body]) => el('button', { type: 'button', className: 'button button--ghost', onclick: () => { text.value = body; } }, label));
    const send = el('button', { type: 'button', className: 'button button--primary' }, 'Отправить в Telegram');
    send.addEventListener('click', async () => {
      if (!text.value.trim()) return;
      send.disabled = true;
      await act(async () => {
        const { data } = await api.post(`/admin/users/${user.id}/telegram-message`, { text: text.value.trim() });
        if (data.failed) toast(`Не доставлено: ${data.failed}`, { tone: 'error' });
      }, 'Сообщение отправлено');
      send.disabled = false;
    });
    return el('section', { className: 'card actions-card' }, el('h3', {}, 'Написать в Telegram'),
      el('div', { className: 'button-row' }, ...templates), text, el('div', { className: 'button-row' }, send));
  }

  await render();
}

function toLocalInput(iso) {
  const d = new Date(iso);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export { formatDate };
