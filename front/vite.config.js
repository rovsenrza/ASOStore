import { readdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

const here = dirname(fileURLToPath(import.meta.url));
const src = resolve(here, 'src');
const shared = resolve(here, '../shared/js');

// Every page in src/ is an entry: the site stays plain multi-page HTML.
const pages = Object.fromEntries(readdirSync(src)
  .filter((file) => file.endsWith('.html'))
  .map((file) => [file.replace(/\.html$/, ''), resolve(src, file)]));

// <!--#header--> / <!--#header on-blue--> / <!--#footer--> / <!--#packages--> / <!--#telegram--> become the shared
// partials, so every page keeps one header and footer without any runtime templating.
function partials() {
  const read = (name) => readFileSync(resolve(src, 'partials', `${name}.html`), 'utf8');
  return {
    name: 'ru-appstore-partials',
    transformIndexHtml: {
      order: 'pre',
      handler: (html) => html
        .replace(/<!--#header(?: ([\w-]+))?-->/, (_, modifier) => read('header').replace('{{modifier}}', modifier ? ` site-header--${modifier}` : ''))
        .replace('<!--#footer-->', read('footer'))
        .replace('<!--#packages-->', read('packages'))
        .replace('<!--#telegram-->', read('telegram')),
    },
  };
}

// Every page (the backend's catalog shell included): Google Search Console ownership, and the
// Yandex.Metrika counter as early in <head> as possible, with its no-script pixel opening <body>.
const ANALYTICS_HEAD = [
  '<meta name="google-site-verification" content="_Y41w8JCtl-MYFW8F7K51_lykIzlSZ3ZM0hW2HXpBdw">',
  '<script src="/js/metrika.js" async></script>',
].join('\n  ');
const ANALYTICS_BODY = '<noscript><div><img src="https://mc.yandex.ru/watch/113565588" class="visually-hidden" alt=""></div></noscript>';

function analytics() {
  return {
    name: 'ru-appstore-analytics',
    transformIndexHtml: {
      order: 'pre',
      handler: (html) => html
        .replace('<meta charset="utf-8">', (charset) => `${charset}\n  ${ANALYTICS_HEAD}`)
        .replace(/<body[^>]*>/, (body) => `${body}\n  ${ANALYTICS_BODY}`),
    },
  };
}

export default defineConfig({
  root: src,
  plugins: [partials(), analytics()],
  publicDir: resolve(here, 'public'),
  resolve: {
    // Pages import the API runtime shared with the admin panel as /shared/js/…; bundle it.
    alias: [{ find: /^\/shared\/js\//, replacement: `${shared}/` }],
  },
  server: {
    fs: { allow: [here, shared] },
    // `npm run dev` talks to the Laravel backend for /api and the mock fixtures.
    proxy: { '/api': 'http://127.0.0.1:8000', '/mock': 'http://127.0.0.1:8000' },
  },
  build: {
    outDir: resolve(here, 'dist'),
    emptyOutDir: true,
    target: 'es2022',
    assetsInlineLimit: 0,
    // The Three.js scene is a lazy chunk loaded only on capable desktops, after the page is idle.
    chunkSizeWarningLimit: 600,
    rollupOptions: { input: pages },
  },
});
