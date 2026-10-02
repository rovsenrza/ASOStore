/**
 * The store's live offer: plans at the bot's current prices, deep links into the bot,
 * the Telegram channel and support chat. One request per page; null when it cannot be
 * loaded, and then the links and prices written in the HTML stay as they are.
 */
let request = null;

export function loadOffer(api) {
  request ??= api.get('/store/offer').then(({ data }) => data).catch(() => null);
  return request;
}

/** Points the Telegram links on the page at the configured bot, channel and support chat. */
export function applyTelegramLinks(offer, root = document) {
  const telegram = offer?.telegram;
  if (!telegram) return;
  const urls = { bot: telegram.bot_url, news: telegram.news_url, support: telegram.support_url };
  root.querySelectorAll('[data-tg-link]').forEach((link) => {
    const url = urls[link.dataset.tgLink];
    if (url) link.href = url;
  });
  if (telegram.bot_username) {
    root.querySelectorAll('[data-tg-username]').forEach((node) => { node.textContent = `@${telegram.bot_username}`; });
  }
  // The referral share is set by the admins in the bot: shown only once the number is real.
  if (Number.isInteger(offer.referral_percent) && offer.referral_percent > 0) {
    root.querySelectorAll('[data-referral-percent]').forEach((node) => { node.textContent = `${offer.referral_percent}%`; });
    root.querySelectorAll('[data-referral]').forEach((node) => { node.hidden = false; });
  }
}
