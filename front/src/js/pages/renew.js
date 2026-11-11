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

const packages = setupPackages(document, { api, returnTo: '/renew.html', authPage: '/login.html', payVerb: 'Продлить за', chooseRegistration: false });

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

async function load() {
  try {
    const { data: user } = await api.get('/auth/me');
    const subscription = user.subscription;
    let block;
    if (subscription?.ends_at) {
      block = notice('notice notice--ok', `Активна до ${dateFormat.format(new Date(subscription.ends_at))}`, `Аккаунт ${user.email}. Новые дни прибавятся к этой дате.`);
    } else if (subscription) {
      block = notice('notice notice--ok', 'Подписка активна без ограничения срока', `Аккаунт ${user.email}. Продлевать её не нужно.`);
    } else {
      block = notice('notice', 'Подписка не активна', `Аккаунт ${user.email}. Выберите срок ниже: доступ включится сразу после оплаты.`);
    }
    state.replaceChildren(block);
    linkBotToAccount();
  } catch (error) {
    if (error?.code === 'UNAUTHENTICATED' || error?.code === 'SESSION_EXPIRED') {
      const next = encodeURIComponent('/renew.html');
      state.replaceChildren(notice('notice', 'Войдите в аккаунт', 'Войдите с той же эл. почтой, на которую оформлена подписка, чтобы увидеть её срок и продлить.', { href: `/login.html?next=${next}`, label: 'Войти' }));
      return;
    }
    state.replaceChildren(notice('notice', 'Не удалось загрузить статус', 'Продлить можно и так: выберите срок ниже и оплатите.'));
  }
}

load();
