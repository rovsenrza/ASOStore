#!/usr/bin/env node
// Builds the store bot's widget app (Bot API 10.4) from backend/resources/telegram-widgets/
// app.html: minifies the CSS and script with esbuild and puts the Ru App Store icon (logo.webp,
// the 48 px mark without the wordmark) in as a data URL. Telegram takes at most 4096 bytes after
// zlib level 9, so the build fails above that. Run after every change to app.html or logo.webp:
//
//   ESBUILD=/path/to/node_modules/esbuild node scripts/build-telegram-widget.mjs
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { deflateSync } from 'node:zlib';

const dir = new URL('../backend/resources/telegram-widgets/', import.meta.url);
const esbuild = createRequire(import.meta.url)(process.env.ESBUILD || 'esbuild');
const source = readFileSync(new URL('app.html', dir), 'utf8');
const logo = 'data:image/webp;base64,' + readFileSync(new URL('logo.webp', dir)).toString('base64');

const css = source.match(/<style>([\s\S]*?)<\/style>/)[1];
const js = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace('__LOGO__', logo);
const minCss = esbuild.transformSync(css, { loader: 'css', minify: true }).code.trim();
const minJs = esbuild.transformSync(js, { loader: 'js', minify: true, format: 'iife', target: 'es2020', charset: 'utf8' }).code.trim();
const html = `<style>${minCss}</style><div id=a></div><script>${minJs}</script>`;

const packed = deflateSync(Buffer.from(html), { level: 9 }).length;
if (packed > 4096) {
  console.error(`app.min.html is ${packed} bytes compressed; Telegram allows 4096.`);
  process.exit(1);
}
writeFileSync(new URL('app.min.html', dir), html);
writeFileSync(new URL('app.min.sha1', dir), createHash('sha1').update(source).digest('hex') + '\n');
console.log(`app.min.html: ${Buffer.byteLength(html)} bytes, ${packed} compressed (limit 4096)`);
