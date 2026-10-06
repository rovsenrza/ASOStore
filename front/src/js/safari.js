/**
 * iOS lets only Safari install the registration profile and apps (itms-services), so
 * customers who opened the site elsewhere on an iPhone — Telegram's built-in browser
 * (links from the bot), Chrome, Yandex — are sent to Safari: once automatically, then
 * with a banner on every page (button, copy link, how-to).
 *
 * Safari reports the frozen build "Mobile/15E148"; apps' web views (Telegram's browser)
 * report the device's real build, and other browsers add their own token.
 * x-safari-https:// opens a page in Safari from other apps (iOS 17+; not iOS 16).
 */
const ua = navigator.userAgent;
const MARK = '_safari';
const SEEN = 'ruas.safari';
const TRIED = 'ruas.safari-tried';
const OTHER_BROWSER = /CriOS|FxiOS|EdgiOS|OPiOS|OPT\/|YaBrowser|YaApp|GSA\/|Ddg\/|DuckDuckGo|Telegram|Instagram|FBAN|FBAV|Line\/|VKClient|Snapchat|musical_ly|TikTok|Twitter|Chrome|Android/i;

// An iPad asking for desktop sites says «Macintosh»; only the touch screen gives it away.
const ipadDesktop = /Macintosh/.test(ua) && navigator.maxTouchPoints > 1;
export const isIos = /iPhone|iPad|iPod/.test(ua) || ipadDesktop;
export const isChrome = /CriOS/.test(ua);
// An app's built-in browser: Safari-like, but with the device's real build. Here it is nearly
// always Telegram's, opened from the bot's links.
const isInAppBrowser = /Version\/[\d.]+ Mobile\/[0-9]+[A-Z][0-9]+[a-z]? Safari\//.test(ua) && !/Mobile\/15E148/.test(ua) && !OTHER_BROWSER.test(ua);

function storage(read, key, value) {
  try {
    if (read) return sessionStorage.getItem(key);
    sessionStorage.setItem(key, value);
  } catch {
    // Storage can be blocked; the page still works, only the once-per-tab memory is lost.
  }
  return null;
}

/** A page opened through the Safari link is in Safari, whatever its user agent says. */
function arrivedViaSafariLink() {
  const url = new URL(location.href);
  if (!url.searchParams.has(MARK)) return storage(true, SEEN) === '1';
  storage(false, SEEN, '1');
  url.searchParams.delete(MARK);
  history.replaceState(history.state, '', url);
  return true;
}

const viaSafariLink = arrivedViaSafariLink();
const safariUserAgent = /Version\/[\d.]+.*Safari\//.test(ua) && !OTHER_BROWSER.test(ua) && (ipadDesktop || /Mobile\/15E148/.test(ua));
export const isSafari = isIos && (safariUserAgent || viaSafariLink);

/** This page (or `path`) as a link that opens in Safari. */
export function safariLink(path = `${location.pathname}${location.search}${location.hash}`) {
  const url = new URL(path, location.href);
  url.searchParams.set(MARK, '1');
  return `x-safari-${url.href}`;
}

/** The plain link to paste into Safari by hand. */
export function plainLink() {
  const url = new URL(location.href);
  url.searchParams.delete(MARK);
  return url.href;
}

function howTo() {
  if (isInAppBrowser || /Telegram/i.test(ua)) return 'Если Safari не открылся: в Telegram нажмите «⋯» в правом верхнем углу и выберите «Открыть в Safari». Или скопируйте ссылку и вставьте её в Safari.';
  if (isChrome) return 'Если Safari не открылся: скопируйте ссылку и вставьте её в адресную строку Safari.';
  return 'Если Safari не открылся: откройте меню браузера и выберите «Открыть в Safari» или скопируйте ссылку и вставьте её в Safari.';
}

/** «Открыть в Safari» and «Скопировать ссылку» buttons with the how-to under them. */
export function safariPrompt({ title = 'Откройте сайт в Safari', text = 'Регистрацию iPhone и установку приложений iOS разрешает только в Safari. Нажмите кнопку — эта страница откроется в Safari.' } = {}) {
  const block = document.createElement('div');
  block.className = 'safari-gate__body';
  block.setAttribute('role', 'region');
  block.setAttribute('aria-label', title);

  const heading = document.createElement('b');
  heading.textContent = title;
  const lead = document.createElement('p');
  lead.textContent = text;

  const actions = document.createElement('div');
  actions.className = 'safari-gate__actions';
  const open = document.createElement('a');
  open.className = 'btn btn--primary btn--small';
  open.href = safariLink();
  open.textContent = 'Открыть в Safari';
  const copy = document.createElement('button');
  copy.type = 'button';
  copy.className = 'btn btn--line btn--small';
  copy.textContent = 'Скопировать ссылку';
  copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(plainLink());
      copy.textContent = 'Ссылка скопирована';
    } catch {
      // No clipboard access (some in-app browsers): show the link to copy by hand.
      window.prompt('Скопируйте ссылку и откройте её в Safari:', plainLink());
    }
  });
  actions.append(open, copy);

  const hint = document.createElement('p');
  hint.className = 'safari-gate__hint';
  hint.textContent = howTo();

  block.append(heading, lead, actions, hint);
  return block;
}

/**
 * On an iPhone outside Safari: try Safari once per tab, and put the prompt at the top of
 * the page. Skipped where Safari is not needed or would break a flow (the iOS app's
 * sign-in sheet, Platega's return page).
 */
export function guideToSafari() {
  if (!isIos || isSafari) return;
  const path = location.pathname;
  const next = new URLSearchParams(location.search).get('next') ?? '';
  if (path === '/app-signin.html' || next.startsWith('/app-signin.html') || path === '/payment.html') return;

  const main = document.querySelector('main');
  if (main) {
    // The homepage header floats over the blue hero; with the banner on top it stays a plain bar.
    document.querySelector('[data-site-header]')?.classList.remove('site-header--on-blue');
    const gate = document.createElement('section');
    gate.className = 'safari-gate';
    const container = document.createElement('div');
    container.className = 'container';
    container.append(safariPrompt());
    gate.append(container);
    main.prepend(gate);
  }

  if (storage(true, TRIED) !== '1') {
    storage(false, TRIED, '1');
    location.href = safariLink();
  }
}
