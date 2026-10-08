#!/usr/bin/env node
/**
 * Snapshots icons of well-known apps that are published in the Ru App Store catalog, for the
 * homepage: one WebP per app (lists) and one atlas (the 3D stage). Only apps the catalog
 * really has are used; a missing app is reported and skipped.
 *
 *   API_BASE=https://ruappstore.com/api/v1 npm run icons
 *
 * Needs ImageMagick (`magick`) for resizing and the atlas.
 */
import { execFileSync } from 'node:child_process';
import { chmodSync, mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const API = process.env.API_BASE ?? 'https://ruappstore.com/api/v1';
const out = resolve(dirname(fileURLToPath(import.meta.url)), '../public/assets/icons');
const TILE = 256;
const COLUMNS = 8;

// Group → [catalog listing name, short label shown on the site], in display order.
const GROUPS = {
  // Only banks listed under their own brand: disguised App Store builds carry unrelated icons.
  banks: [['СберБанк Онлайн', 'СберБанк'], ['Т-Банк', 'Т-Банк'], ['Альфа-Банк', 'Альфа-Банк'], ['Газпромбанк', 'Газпромбанк'], ['Россельхозбанк', 'Россельхозбанк'], ['ПСБ', 'ПСБ'], ['МТС Банк', 'МТС Банк'], ['Халва — Совкомбанк', 'Халва'], ['Банк ДОМ.РФ', 'ДОМ.РФ'], ['Райффайзен Онлайн Банк Россия', 'Райффайзен'], ['ОТП Банк Онлайн', 'ОТП Банк'], ['Ozon Банк', 'Ozon Банк'], ['Ак Барс Банк', 'Ак Барс'], ['Уралсиб Онлайн', 'Уралсиб'], ['МКБ Банк', 'МКБ'], ['Банк Санкт-Петербург', 'Банк СПб'], ['Новикомбанк', 'Новикомбанк'], ['АТБ банк', 'АТБ'], ['Цифра банк', 'Цифра банк'], ['Альфа-Инвестиции', 'Альфа-Инвестиции'], ['Kaspi.kz суперприложение', 'Kaspi.kz']],
  shopping: [['OZON: товары, одежда, билеты', 'Ozon'], ['WILDBERRIES', 'Wildberries'], ['Яндекс Маркет: покупки онлайн', 'Яндекс Маркет'], ['AliExpress', 'AliExpress'], ['Авито', 'Авито'], ['СДЭК: Доставка и Шопинг', 'СДЭК'], ['Пятёрочка: доставка продуктов', 'Пятёрочка'], ['Яндекс Go: Такси Еда Доставка', 'Яндекс Go']],
  social: [['Instagram', 'Instagram'], ['WhatsApp Messenger', 'WhatsApp'], ['TikTok', 'TikTok'], ['Threads', 'Threads'], ['X', 'X'], ['Общайтесь и играйте с Discord', 'Discord'], ['Rakuten Viber Messenger', 'Viber'], ['ВКонтакте: общение в соцсети', 'VK']],
  media: [['YouTube Music - музыка и клипы', 'YouTube Music'], ['Spotify: музыка и подкасты', 'Spotify'], ['Netflix', 'Netflix'], ['Okko', 'Okko'], ['RUTUBE', 'RUTUBE'], ['Яндекс Музыка', 'Яндекс Музыка'], ['VK Музыка', 'VK Музыка'], ['VK Видео', 'VK Video']],
  services: [['Госуслуги', 'Госуслуги'], ['Мой МТС', 'Мой МТС'], ['МегаФон', 'МегаФон'], ['билайн', 'билайн'], ['Моя Москва — приложение mos.ru', 'Моя Москва'], ['hh: поиск работы', 'hh.ru'], ['Яндекс Карты и Навигатор', 'Яндекс Карты'], ['Почта Mail.ru', 'Почта Mail.ru']],
};

async function catalog() {
  const apps = [];
  for (let page = 1; ; page += 1) {
    const response = await fetch(`${API}/apps?per_page=50&page=${page}&sort=name`);
    if (!response.ok) throw new Error(`GET /apps page ${page}: ${response.status}`);
    const { data, meta } = await response.json();
    apps.push(...data);
    if (page >= meta.pagination.last_page) return apps;
  }
}

const apps = await catalog();
rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });
const manifest = [];
const tiles = [];
for (const [group, names] of Object.entries(GROUPS)) {
  for (const [name, label] of names) {
    const app = apps.find((item) => item.name === name);
    if (!app?.icon_url) {
      console.warn(`skip ${name}: not in the catalog or no icon`);
      continue;
    }
    const png = await (await fetch(app.icon_url)).arrayBuffer();
    const file = `${app.id}.webp`;
    const source = resolve(out, `${file}.src.png`);
    writeFileSync(source, Buffer.from(png));
    execFileSync('magick', [source, '-resize', `${TILE}x${TILE}`, '-quality', '86', resolve(out, file)]);
    tiles.push(source);
    manifest.push({ id: app.id, name: app.name, label, group, file, index: manifest.length });
  }
}

// Atlas for the 3D stage: tiles in rows of COLUMNS, same order as the manifest.
const rows = Math.ceil(tiles.length / COLUMNS);
const rowFiles = [];
for (let row = 0; row < rows; row += 1) {
  const cells = tiles.slice(row * COLUMNS, (row + 1) * COLUMNS).flatMap((tile) => ['(', tile, '-resize', `${TILE}x${TILE}!`, ')']);
  const file = resolve(out, `row-${row}.png`);
  execFileSync('magick', ['-background', 'none', ...cells, '+append', '-gravity', 'west', '-extent', `${TILE * COLUMNS}x${TILE}`, file]);
  rowFiles.push(file);
}
execFileSync('magick', ['-background', 'none', ...rowFiles, '-append', '-quality', '88', resolve(out, 'atlas.webp')]);
for (const file of [...tiles, ...rowFiles]) rmSync(file);

writeFileSync(resolve(out, 'icons.json'), `${JSON.stringify({ source: API, tile: TILE, columns: COLUMNS, rows, apps: manifest }, null, 2)}\n`);
// Readable by the web server whatever the local umask.
for (const file of readdirSync(out)) chmodSync(resolve(out, file), 0o644);
console.log(`${manifest.length} icons, atlas ${COLUMNS}x${rows}`);
