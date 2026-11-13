import { boot, redirectUnverified, renderError, renderLoading } from '../app.js';
import { setupPackages } from '../packages.js';
import { isIos, isSafari } from '../safari.js';
import { loadOffer } from '../store-offer.js';
import { rememberDevice, thisDevice } from '/shared/js/api-client.js';

/**
 * «Мои устройства»: every iPhone of the account, each with its own subscription (paid per iPhone),
 * drawn as an iPhone whose screen shows a pulsing state light. A new iPhone is added to the
 * account here (paid like any plan), then connected by installing the profile on it.
 */
const { api, t } = boot();
const grid = document.querySelector('#devices');
const count = document.querySelector('#devices-count');
const addSection = document.querySelector('#add');
const connect = document.querySelector('#connect');
const dateFormat = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });

const STATES = {
  ready: { tone: 'ok', label: 'Активен' },
  waiting: { tone: 'wait', label: 'Ждёт Apple' },
  connect: { tone: 'wait', label: 'Ждёт подключения' },
  blocked: { tone: 'bad', label: 'Нужна помощь' },
  expired: { tone: 'bad', label: 'Подписка закончилась' },
};

const packages = setupPackages(document, {
  api,
  returnTo: '/devices.html',
  authPage: '/login.html',
  payVerb: 'Добавить iPhone за',
  extra: () => ({ new_device: true }),
});
loadOffer(api).then((offer) => packages?.applyOffer(offer));

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text != null) node.textContent = text;
  return node;
}

function link(text, href, className = 'btn btn--line btn--small') {
  const node = el('a', className, text);
  node.href = href;
  return node;
}

function button(text, onClick, className = 'btn btn--line btn--small') {
  const node = el('button', className, text);
  node.type = 'button';
  node.addEventListener('click', onClick);
  return node;
}

function term(device) {
  const { access } = device;
  if (access.unlimited) return 'Бессрочно';
  if (!access.ends_at) return 'Не оплачен';
  const date = dateFormat.format(new Date(access.ends_at));
  return access.active ? `до ${date}` : `закончилась ${date}`;
}

/** An iPhone frame with the device's state on its screen. */
function phone(device, current) {
  const state = STATES[device.state] ?? STATES.blocked;
  const frame = el('div', `iphone iphone--${state.tone}${device.connected ? '' : ' iphone--pending'}`);
  frame.append(el('span', 'iphone__island'));
  const screen = el('div', 'iphone__screen');
  const light = el('div', 'iphone__status');
  light.append(el('span', 'pulse'), el('span', null, state.label));
  screen.append(
    light,
    el('strong', 'iphone__model', device.connected ? (device.model ?? 'iPhone') : 'Новый iPhone'),
    el('span', 'iphone__term', term(device)),
  );
  if (device.access.active && device.access.days_left != null) {
    const left = el('span', 'iphone__days');
    left.append(el('b', null, String(device.access.days_left)), ` ${plural(device.access.days_left, 'день', 'дня', 'дней')}`);
    screen.append(left);
  }
  screen.append(el('span', 'iphone__meta', device.connected
    ? `${device.method === 'instant' ? '⚡ мгновенно' : '🛡 через Apple'} · ••${(device.udid_hint ?? '').slice(-4)}`
    : 'оплачен, ждёт подключения'));
  if (current) screen.append(el('span', 'iphone__here', 'Это устройство'));
  frame.append(screen);
  return frame;
}

function plural(n, one, few, many) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return one;
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return few;
  return many;
}

function card(device, current, reload) {
  const item = el('article', 'device-card');
  item.setAttribute('role', 'listitem');
  item.append(phone(device, current));
  const actions = el('div', 'device-card__actions');
  if (device.state === 'connect') {
    actions.append(button('Как подключить', () => showConnect(device), 'btn btn--primary btn--small'));
  } else {
    actions.append(link(device.access.active ? 'Продлить' : 'Продлить сейчас', `/renew.html?device=${encodeURIComponent(device.id)}#pricing`,
      device.access.active ? 'btn btn--line btn--small' : 'btn btn--primary btn--small'));
  }
  if (device.connected && !current) {
    actions.append(button('Это мой iPhone', () => { rememberDevice(device.id); reload(); }, 'link-button'));
  }
  item.append(actions);
  return item;
}

function addCard() {
  const item = el('article', 'device-card device-card--add');
  item.setAttribute('role', 'listitem');
  const frame = el('button', 'iphone iphone--add');
  frame.type = 'button';
  frame.append(el('span', 'iphone__island'));
  const screen = el('span', 'iphone__screen');
  screen.append(el('span', 'iphone__plus', '+'), el('strong', 'iphone__model', 'Добавить iPhone'), el('span', 'iphone__meta', 'со своей подпиской'));
  frame.append(screen);
  frame.addEventListener('click', openAdd);
  item.append(frame);
  return item;
}

function openAdd() {
  addSection.hidden = false;
  addSection.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
}

/** How to connect a paid, not yet connected iPhone; the profile button when this is that iPhone. */
function showConnect(device) {
  const body = document.querySelector('#connect-body');
  const steps = el('ol', 'connect-steps');
  for (const text of [
    'Возьмите новый iPhone и откройте ruappstore.com/devices.html в Safari.',
    'Войдите в этот же аккаунт.',
    'Нажмите «Подключить этот iPhone» и установите профиль: Настройки → Профиль загружен → Установить.',
    'Регистрация займёт до 72 часов при подключении через Apple или пару минут при мгновенном способе — статус виден здесь.',
  ]) steps.append(el('li', null, text));
  body.replaceChildren(steps);
  // On an iPhone in Safari that is not one of the account's connected iPhones: install here.
  if (isIos && isSafari && !thisDevice()) {
    const install = link('Подключить этот iPhone', '/api/v1/devices/enrollment-profile', 'btn btn--primary');
    body.append(install);
  } else if (!isIos) {
    body.append(el('p', 'field-hint', 'Эту страницу нужно открыть на самом новом iPhone.'));
  }
  connect.hidden = false;
  connect.dataset.device = device.id;
  connect.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function load() {
  renderLoading(grid, t);
  try {
    const { data: devices, meta } = await api.get('/devices/me');
    const current = thisDevice() && devices.some((d) => d.id === thisDevice()) ? thisDevice() : meta.current;
    grid.replaceChildren(...devices.map((device) => card(device, device.id === current, load)));
    if (meta.can_add) grid.append(addCard());
    count.textContent = `${devices.length} из ${meta.limit}`;
    const pending = devices.find((device) => device.state === 'connect');
    if (pending && new URLSearchParams(location.search).has('connect')) showConnect(pending);
    if (new URLSearchParams(location.search).has('add') && meta.can_add) openAdd();
  } catch (error) {
    if (error.code === 'EMAIL_NOT_VERIFIED' && redirectUnverified({ stage: 'email_verification_required' })) return;
    if (error.code === 'UNAUTHENTICATED') {
      location.assign(`/login.html?next=${encodeURIComponent(location.pathname + location.search)}`);
      return;
    }
    renderError(grid, t, error, load);
  }
}

load();
