import { boot, el, errorNotice } from '../app.js';
import { toast } from '../components/toast.js';
import { drawFrame, buildScene, WIDTH, HEIGHT, DURATION, FPS } from '../promo-video.js';

/**
 * «… получил обновления» promo video. The operator types an app name (or picks a
 * match); the clip's colours, icon, name and details come from that app's catalog
 * listing, over the fixed template. Everything runs in the browser: the preview is
 * canvas drawing, the download is a MediaRecorder capture of the same frames.
 */
const { api, t } = await boot();
const box = document.querySelector('#promo');

const brand = loadImage('assets/brand/ru-app-store-logo-180.png').catch(() => null);

const search = el('input', { type: 'search', className: 'input', placeholder: 'Название приложения, например Банк Хлынов', autocomplete: 'off' });
const results = el('div', { className: 'promo-results' });
const canvas = el('canvas', { width: WIDTH, height: HEIGHT, className: 'promo-canvas' });
const scrub = el('input', { type: 'range', min: '0', max: String(DURATION), step: '0.01', value: '2.6', className: 'promo-scrub', disabled: true });
const makeBtn = el('button', { type: 'button', className: 'button button--primary', disabled: true }, 'Сделать видео');
const status = el('p', { className: 'muted' });
const ctx = canvas.getContext('2d');

let scene = null;
let searchTimer = null;

box.replaceChildren(
  el('p', { className: 'muted' }, 'Шаблон один и тот же — меняются только название, иконка, цвет и строка версии/размера выбранного приложения.'),
  el('label', { className: 'field' }, 'Приложение', search),
  results,
  el('div', { className: 'promo-stage' }, canvas),
  el('div', { className: 'promo-controls' }, scrub, makeBtn),
  status,
);

function loadImage(src) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => resolve(img);
    img.onerror = reject;
    img.src = src;
  });
}

search.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = search.value.trim();
  if (q.length < 2) { results.replaceChildren(); return; }
  searchTimer = setTimeout(() => findApps(q), 250);
});

async function findApps(q) {
  try {
    const { data } = await api.get(`/admin/apps?q=${encodeURIComponent(q)}&per_page=8`);
    results.replaceChildren(...(data.length ? data.map(resultRow) : [el('p', { className: 'muted' }, 'Ничего не найдено.')]));
  } catch (error) {
    results.replaceChildren(errorNotice(t, error, () => findApps(q)));
  }
}

function resultRow(app) {
  return el('button', { type: 'button', className: 'promo-result', onclick: () => choose(app.id) },
    app.icon_url ? el('img', { src: app.icon_url, alt: '', width: 40, height: 40 }) : el('span', { className: 'thumb thumb--empty' }, app.name.slice(0, 1)),
    el('span', {}, el('b', {}, app.name), el('small', { className: 'muted' }, (app.publisher && app.publisher.name) || '')));
}

async function choose(id) {
  status.textContent = 'Загружаем приложение…';
  results.replaceChildren();
  try {
    const { data: app } = await api.get(`/admin/apps/${id}`);
    const icon = app.icon_url ? await loadImage(app.icon_url) : null;
    scene = buildScene(app, icon, await brand);
    search.value = app.name;
    scrub.disabled = false;
    makeBtn.disabled = false;
    status.textContent = '';
    renderPreview();
  } catch (error) {
    status.replaceChildren(errorNotice(t, error, () => choose(id)));
  }
}

function renderPreview() {
  if (scene) drawFrame(ctx, parseFloat(scrub.value), scene);
}

scrub.addEventListener('input', renderPreview);

makeBtn.addEventListener('click', record);

function pickMime() {
  for (const m of ['video/mp4;codecs=avc1', 'video/webm;codecs=vp9', 'video/webm']) {
    if (window.MediaRecorder && MediaRecorder.isTypeSupported(m)) return m;
  }
  return '';
}

async function record() {
  if (!scene) return;
  const mime = pickMime();
  if (!mime) { status.textContent = 'Браузер не умеет записывать видео. Откройте панель в Chrome.'; return; }
  makeBtn.disabled = true; scrub.disabled = true;
  const stream = canvas.captureStream(FPS);
  const chunks = [];
  const rec = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 12_000_000 });
  rec.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
  const done = new Promise((resolve) => { rec.onstop = resolve; });
  rec.start();

  const start = performance.now();
  await new Promise((resolve) => {
    const tick = (now) => {
      const elapsed = (now - start) / 1000;
      const time = Math.min(elapsed, DURATION);
      drawFrame(ctx, time, scene);
      status.textContent = `Запись… ${Math.round((time / DURATION) * 100)}%`;
      if (elapsed < DURATION) requestAnimationFrame(tick);
      else setTimeout(resolve, 60);
    };
    requestAnimationFrame(tick);
  });
  rec.stop();
  await done;

  const ext = mime.startsWith('video/mp4') ? 'mp4' : 'webm';
  const blob = new Blob(chunks, { type: mime });
  const name = `${new Date().toISOString().slice(0, 10)}_обновление_${scene.name}`.replace(/[^\p{L}\p{N}_]+/gu, '_');
  const a = el('a', { href: URL.createObjectURL(blob), download: `${name}.${ext}` });
  document.body.append(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 10_000);

  status.textContent = `Готово: ${name}.${ext} (${(blob.size / 1048576).toFixed(1)} МБ). Файл скачан.`;
  makeBtn.disabled = false; scrub.disabled = false;
  renderPreview();
  toast('Видео готово', { tone: 'ok' });
}
