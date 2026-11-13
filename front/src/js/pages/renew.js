import { boot } from '../app.js';
import { setupPackages } from '../packages.js';
import { applyTelegramLinks, loadOffer } from '../store-offer.js';

/**
 * «Продлить подписку»: the purchase block for someone who already has an account. Who is signed
 * in and until when their access runs comes first; paying goes through the same website order,
 * whose code is redeemed on the account, and redeeming adds the days to the current term.
 */
const { api } = boot();
const state = document.querySelector('#renew-state');
const dateFormat = new Intl.DateTimeFormat('ru-RU', { dateStyle: 'long' });

// Access is paid per iPhone: ?device=<id> extends that iPhone (from «Мои устройства»).
let deviceId = new URLSearchParams(location.search).get('device');
const packages = setupPackages(document, {
  api,
  returnTo: deviceId ? `/renew.html?device=${encodeURIComponent(deviceId)}` : '/renew.html',
  authPage: '/login.html',
  payVerb: 'Продлить за',
  chooseRegistration: false,
  extra: () => (deviceId ? { device_id: deviceId } : {}),
});

/**
 * A one-time bot link that carries the signed-in account (POST /store/telegram-link), so the
 * bot extends this very account instead of asking which one or selling a code. Null for a guest.
 */
let accountBotUrl = null;

/** The Telegram button of the block opens the bot's renewal instead of a new purchase. */
function pointCtaAtRenewal() {
  const cta = packages?.querySelector('[data-offer-cta]');
  if (!cta) return;
  cta.href = accountBotUrl ?? `${cta.href.split('?')[0]}?start=renew`;
  packages.querySelector('[data-offer-cta-label]').textContent = 'Или продлить в Telegram';
}

async function linkBotToAccount() {
  try {
    const { data } = await api.post('/store/telegram-link', {});
    if (typeof data?.url !== 'string' || !/^https:\/\/t\.me\//i.test(data.url)) return;
    accountBotUrl = data.url;
    pointCtaAtRenewal();
    const button = document.querySelector('[data-renew-bot]');
    if (button) button.href = accountBotUrl;
  } catch {
    // The plain renewal link stays: the bot then finds the account by email.
  }
}
packages?.addEventListener('plan-change', pointCtaAtRenewal);
pointCtaAtRenewal();

loadOffer(api).then((offer) => {
  packages?.applyOffer(offer);
  applyTelegramLinks(offer);
  const bot = offer?.telegram?.bot_url;
  if (typeof bot === 'string' && /^https:\/\//i.test(bot) && !accountBotUrl) {
    document.querySelector('[data-renew-bot]').href = `${bot.split('?')[0]}?start=renew`;
  }
});

function notice(tone, title, text, action = null) {
  const block = document.createElement('div');
  block.className = tone;
  const heading = document.createElement('b');
  heading.textContent = title;
  const body = document.createElement('p');
  body.textContent = text;
  block.append(heading, body);
  if (action) {
    const row = document.createElement('div');
    row.className = 'stage-actions';
    const link = document.createElement('a');
    link.className = 'btn btn--primary btn--small';
    link.href = action.href;
    link.textContent = action.label;
    row.append(link);
    block.append(row);
  }
  return block;
}

const dayFormat = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });

/** One iPhone as a choice: model, last characters, until when. */
function deviceLine(device) {
  const until = device.access.unlimited ? 'бессрочно'
    : device.access.ends_at ? `${device.access.active ? 'до' : 'закончилась'} ${dayFormat.format(new Date(device.access.ends_at))}` : 'не оплачен';
  return `${device.connected ? device.model ?? 'iPhone' : 'Новый iPhone'}${device.connected ? ` ••${(device.udid_hint ?? '').slice(-4)}` : ''} · ${until}`;
}

async function load() {
  try {
    const [{ data: user }, { data: devices }] = await Promise.all([api.get('/auth/me'), api.get('/devices/me')]);
    if (devices.length === 1) deviceId = devices[0].id;
    const device = devices.find((d) => d.id === deviceId);
    let block;
    if (!device && devices.length > 1) {
      // Several iPhones: which one to extend (each is paid for on its own).
      block = notice('notice', 'Какой iPhone продлить?', `Аккаунт ${user.email}. У каждого iPhone своя подписка — выберите нужный.`);
      const list = document.createElement('div');
      list.className = 'stage-actions';
      for (const item of devices) {
        const choice = document.createElement('a');
        choice.className = 'btn btn--line btn--small';
        choice.href = `/renew.html?device=${encodeURIComponent(item.id)}#pricing`;
        choice.textContent = deviceLine(item);
        list.append(choice);
      }
      block.append(list);
    } else if (device?.access.unlimited) {
      block = notice('notice notice--ok', 'Подписка активна без ограничения срока', `${deviceLine(device)}. Продлевать её не нужно.`);
    } else if (device?.access.active) {
      block = notice('notice notice--ok', `${device.connected ? device.model ?? 'iPhone' : 'Новый iPhone'}: активна до ${dateFormat.format(new Date(device.access.ends_at))}`, `Аккаунт ${user.email}. Новые дни прибавятся к этой дате.`);
    } else if (device) {
      block = notice('notice', `${device.connected ? device.model ?? 'iPhone' : 'Новый iPhone'}: подписка не активна`, `Аккаунт ${user.email}. Выберите срок ниже: доступ включится сразу после оплаты.`);
    } else {
      block = notice('notice', 'Подписка не активна', `Аккаунт ${user.email}. Выберите срок ниже: доступ включится сразу после оплаты.`);
    }
    if (devices.length > 1 || device) {
      const all = document.createElement('p');
      all.className = 'field-hint';
      all.innerHTML = '<a href="/devices.html">Все мои устройства →</a>';
      block.append(all);
    }
    state.replaceChildren(block);
    linkBotToAccount();
  } catch (error) {
    if (error?.code === 'UNAUTHENTICATED' || error?.code === 'SESSION_EXPIRED') {
      const next = encodeURIComponent(`/renew.html${location.search}`);
      state.replaceChildren(notice('notice', 'Войдите в аккаунт', 'Войдите с той же эл. почтой, на которую оформлена подписка, чтобы увидеть её срок и продлить.', { href: `/login.html?next=${next}`, label: 'Войти' }));
      return;
    }
    state.replaceChildren(notice('notice', 'Не удалось загрузить статус', 'Продлить можно и так: выберите срок ниже и оплатите.'));
  }
}

load();
