/**
 * "<App> получил обновления" promo clip for the group channel. 1920x1080, 5 s,
 * 30 fps. The layout is fixed (it reuses the 2026-10-07 reference clip); only the
 * app name, icon, brand colour and the version/size/age/category line are data.
 * drawFrame(ctx, t, scene) is pure, so the live preview and the MediaRecorder
 * capture render identical frames. scene = buildScene(app, icon).
 */

export const WIDTH = 1920;
export const HEIGHT = 1080;
export const DURATION = 5;
export const FPS = 30;
export const FONT = 'Manrope';

const clamp = (v, a = 0, b = 1) => Math.min(b, Math.max(a, v));
const span = (t, a, b) => clamp((t - a) / (b - a));
const easeOut = (x) => 1 - (1 - x) ** 3;
const mix = (a, b, k) => a + (b - a) * k;
const rgb = (c, a = 1) => `rgba(${c.map(Math.round).join(',')},${a})`;

const PHONE = { x: 1050, y: 150, w: 760, r: 120, bezel: 15 };
const SCR = { x: PHONE.x + PHONE.bezel, y: PHONE.y + PHONE.bezel, w: PHONE.w - 2 * PHONE.bezel, r: PHONE.r - PHONE.bezel };

/** Saturation-weighted average of the icon's coloured pixels (ignores white/grey/black). */
export function iconColor(image) {
  const n = 48;
  const cv = document.createElement('canvas');
  cv.width = cv.height = n;
  const c = cv.getContext('2d', { willReadFrequently: true });
  c.drawImage(image, 0, 0, n, n);
  const d = c.getImageData(0, 0, n, n).data;
  let r = 0; let g = 0; let b = 0; let w = 0; let ar = 0; let ag = 0; let ab = 0; let m = 0;
  for (let i = 0; i < d.length; i += 4) {
    if (d[i + 3] < 128) continue;
    const [pr, pg, pb] = [d[i], d[i + 1], d[i + 2]];
    ar += pr; ag += pg; ab += pb; m++;
    const mx = Math.max(pr, pg, pb); const mn = Math.min(pr, pg, pb);
    const s = mx === 0 ? 0 : (mx - mn) / mx;
    if (s < 0.22 || mx < 40) continue;
    const k = s * s;
    r += pr * k; g += pg * k; b += pb * k; w += k;
  }
  return w > 0 ? [r / w, g / w, b / w] : (m > 0 ? [ar / m, ag / m, ab / m] : [120, 140, 170]);
}

export function formatSize(bytes) {
  if (!bytes) return '—';
  const mb = bytes / 1048576;
  return mb >= 1024 ? `${(mb / 1024).toFixed(1).replace('.', ',')} ГБ` : `${mb.toFixed(1).replace('.', ',')} МБ`;
}

export function buildScene(app, icon, brand = null) {
  const v = app.latest_version || {};
  const color = icon ? iconColor(icon) : [91, 154, 245];
  return {
    name: app.name,
    publisher: (app.publisher && app.publisher.name) || '',
    icon,
    brand,
    color,
    tint: color.map((c) => mix(c, 188, 0.5)),
    meta: [
      ['ВЕРСИЯ', v.released_at ? v.released_at.slice(0, 10).replace(/-/g, '.') : (v.version || '—')],
      ['ВОЗРАСТ', app.age_rating || '4+'],
      ['РАЗМЕР', formatSize(v.size_bytes)],
      ['КАТЕГ…', (app.category && app.category.title) || '—'],
    ],
  };
}

const rr = (c, x, y, w, h, r) => { c.beginPath(); c.roundRect(x, y, w, h, r); };

function fit(c, text, weight, size, maxW, min = 16) {
  let s = size;
  c.font = `${weight} ${s}px ${FONT}`;
  while (s > min && c.measureText(text).width > maxW) { s -= 1; c.font = `${weight} ${s}px ${FONT}`; }
  return s;
}

function wrap(c, text, maxW) {
  const out = []; let line = '';
  for (const word of text.split(/\s+/).filter(Boolean)) {
    const next = line ? `${line} ${word}` : word;
    if (line && c.measureText(next).width > maxW) { out.push(line); line = word; } else line = next;
  }
  if (line) out.push(line);
  return out;
}

function icon(c, img, x, y, s, r, shadow = true) {
  c.save();
  if (shadow) { c.shadowColor = 'rgba(0,0,0,.35)'; c.shadowBlur = 28; c.shadowOffsetY = 10; }
  rr(c, x, y, s, s, r); c.fillStyle = '#fff'; c.fill();
  c.restore();
  c.save(); rr(c, x, y, s, s, r); c.clip();
  if (img) c.drawImage(img, x, y, s, s);
  c.restore();
}

function background(c) {
  c.fillStyle = '#f4f7fb'; c.fillRect(0, 0, WIDTH, HEIGHT);
  for (const [x, y, r, col] of [[1760, 70, 900, 'rgba(206,224,252,.95)'], [140, 1120, 720, 'rgba(214,228,250,.8)'], [630, 300, 520, 'rgba(255,255,255,.9)']]) {
    const g = c.createRadialGradient(x, y, 0, x, y, r);
    g.addColorStop(0, col); g.addColorStop(1, 'rgba(220,232,252,0)');
    c.fillStyle = g; c.fillRect(0, 0, WIDTH, HEIGHT);
  }
}

function statusBar(c, x, y) {
  c.fillStyle = '#fff'; c.textBaseline = 'alphabetic';
  c.font = `700 30px ${FONT}`; c.fillText('9:41', x + 70, y + 66);
  c.fillStyle = '#000'; rr(c, x + 282, y + 28, 165, 50, 25); c.fill();
  c.fillStyle = '#fff';
  const by = y + 70;
  for (let i = 0; i < 4; i++) { c.globalAlpha = i === 3 ? 0.4 : 1; rr(c, x + 536 + i * 9, by - 8 - i * 5, 6, 8 + i * 5, 1.5); c.fill(); }
  c.globalAlpha = 1; c.strokeStyle = '#fff'; c.lineCap = 'round'; c.lineWidth = 4;
  for (const r of [18, 11]) { c.beginPath(); c.arc(x + 604, by + 1, r, Math.PI * 1.25, Math.PI * 1.75); c.stroke(); }
  c.beginPath(); c.arc(x + 604, by - 1, 3, 0, 7); c.fill();
  c.lineWidth = 2; c.globalAlpha = 0.45; rr(c, x + 630, by - 18, 44, 20, 6); c.stroke();
  c.globalAlpha = 1; rr(c, x + 633, by - 15, 30, 14, 4); c.fill();
}

function drawScreen(c, t, scene) {
  const { x, y, w } = SCR;
  c.save(); rr(c, x, y, w, HEIGHT, SCR.r); c.clip();
  c.fillStyle = '#000'; c.fillRect(x, y, w, HEIGHT);

  const h = c.createLinearGradient(0, y, 0, y + 380);
  h.addColorStop(0, rgb(scene.tint)); h.addColorStop(0.45, rgb(scene.tint.map((v) => v * 0.72))); h.addColorStop(1, '#000');
  c.fillStyle = h; c.fillRect(x, y, w, 380);
  c.strokeStyle = 'rgba(255,255,255,.12)'; c.lineWidth = 1.5;
  for (const cx of [x - 60, x + w + 60]) for (let r = 70; r < 420; r += 52) { c.beginPath(); c.arc(cx, y + 190, r, 0, 7); c.stroke(); }
  const fade = c.createLinearGradient(0, y + 250, 0, y + 400);
  fade.addColorStop(0, 'rgba(0,0,0,0)'); fade.addColorStop(1, '#000');
  c.fillStyle = fade; c.fillRect(x, y + 250, w, 150);

  statusBar(c, x, y);

  c.fillStyle = 'rgba(70,70,70,.5)'; c.beginPath(); c.arc(x + 52, y + 250, 31, 0, 7); c.fill();
  c.strokeStyle = '#fff'; c.lineWidth = 3.5; c.lineCap = 'round';
  c.beginPath(); c.moveTo(x + 58, y + 239); c.lineTo(x + 47, y + 250); c.lineTo(x + 58, y + 261); c.stroke();

  icon(c, scene.icon, x + w / 2 - 114, y + 115, 228, 50);

  const info = easeOut(span(t, 0.62, 0.92));
  c.globalAlpha = info;
  const tx = x + 258; const ny = y + 548 + (1 - info) * 12;
  const ns = fit(c, scene.name, 700, 36, w - 285, 22);
  c.fillStyle = '#fff'; c.textBaseline = 'alphabetic'; c.fillText(scene.name, tx, ny);
  fit(c, scene.publisher, 500, 23, w - 285, 15);
  c.fillStyle = '#7aa2ff'; c.fillText(scene.publisher, tx, ny + ns * 0.1 + 36);
  c.globalAlpha = 1;

  const pop = easeOut(span(t, 0.72, 1.0));
  if (pop > 0) { const s = 200 * mix(0.6, 1, pop); c.globalAlpha = pop; icon(c, scene.icon, x + 30 + (200 - s) / 2, y + 395 + (200 - s) / 2, s, 44 * s / 200, false); c.globalAlpha = 1; }

  const btn = easeOut(span(t, 0.82, 1.1));
  if (btn > 0) drawButton(c, t, x, y, w, btn);

  // Meta row (ВЕРСИЯ / ВОЗРАСТ / РАЗМЕР / КАТЕГОРИЯ)
  const mrow = easeOut(span(t, 1.0, 1.25));
  c.globalAlpha = mrow;
  c.strokeStyle = 'rgba(255,255,255,.12)'; c.lineWidth = 1;
  c.beginPath(); c.moveTo(x + 30, y + 806); c.lineTo(x + w - 30, y + 806); c.stroke();
  const cols = scene.meta.length; const cw = (w - 60) / cols;
  scene.meta.forEach(([label, value], i) => {
    const mx = x + 30 + cw * i + cw / 2;
    c.textAlign = 'center';
    c.fillStyle = 'rgba(255,255,255,.5)'; c.font = `700 18px ${FONT}`; c.fillText(label, mx, y + 852);
    c.fillStyle = '#fff'; fit(c, value, 700, 28, cw - 16, 16); c.fillText(value, mx, y + 892);
    if (i > 0) { c.strokeStyle = 'rgba(255,255,255,.1)'; c.beginPath(); c.moveTo(x + 30 + cw * i, y + 828); c.lineTo(x + 30 + cw * i, y + 878); c.stroke(); }
  });
  c.textAlign = 'left'; c.globalAlpha = 1;
  c.restore();
}

/** Install button → «Загрузка N%» progress bar → «Открыть». */
function drawButton(c, t, x, y, w, alpha) {
  const bw = 676; const bx = x + 30; const by = y + 640 + (1 - alpha) * 22;
  c.globalAlpha = alpha; c.textAlign = 'center'; c.textBaseline = 'middle';
  const downloading = t >= 2.0 && t < 3.6;
  const opened = t >= 3.6;
  if (downloading) {
    const p = span(t, 2.0, 3.6);
    rr(c, bx, by, bw, 92, 46); c.fillStyle = '#1f2f4a'; c.fill();
    c.save(); rr(c, bx, by, bw, 92, 46); c.clip();
    c.fillStyle = '#4b86f0'; c.fillRect(bx, by, bw * p, 92); c.restore();
    c.fillStyle = '#fff'; c.font = `700 30px ${FONT}`;
    c.fillText(`Загрузка ${Math.round(p * 100)}%`, bx + bw / 2, by + 48);
  } else {
    rr(c, bx, by, bw, 92, 46); c.fillStyle = '#4b86f0'; c.fill();
    c.fillStyle = '#fff'; c.font = `700 32px ${FONT}`;
    if (opened) c.fillText('Открыть', bx + bw / 2, by + 48);
    else {
      const label = 'Установить'; const m = c.measureText(label).width;
      c.save(); c.translate(bx + bw / 2 - m / 2 - 22, by + 46);
      c.strokeStyle = '#fff'; c.lineWidth = 3.5; c.lineCap = 'round'; c.lineJoin = 'round';
      c.beginPath(); c.moveTo(0, -11); c.lineTo(0, 10); c.moveTo(-8, 2); c.lineTo(0, 11); c.lineTo(8, 2); c.stroke();
      c.restore();
      c.fillText(label, bx + bw / 2 + 16, by + 48);
    }
  }
  c.globalAlpha = 1; c.textAlign = 'left'; c.textBaseline = 'alphabetic';
}

function drawPhone(c, scene) {
  c.save();
  c.shadowColor = 'rgba(40,60,100,.3)'; c.shadowBlur = 90; c.shadowOffsetY = 40;
  rr(c, PHONE.x, PHONE.y, PHONE.w, HEIGHT + 300, PHONE.r);
  c.fillStyle = '#0b0f17'; c.fill();
  c.restore();
}

function drawTitle(c, t, scene) {
  const logoIn = easeOut(span(t, 0.1, 0.4));
  c.globalAlpha = logoIn;
  const lx = 150; const ly = 400;
  icon(c, scene.brand, lx, ly - 46, 56, 14, false);
  c.fillStyle = '#1a2d52'; c.font = `700 40px ${FONT}`; c.textBaseline = 'alphabetic';
  c.fillText('Ru App Store', lx + 74, ly - 4);
  c.globalAlpha = 1;

  const titleIn = easeOut(span(t, 0.32, 0.75));
  c.globalAlpha = titleIn;
  c.fillStyle = '#16233f'; const size = 92;
  c.font = `800 ${size}px ${FONT}`;
  const nameLines = wrap(c, scene.name, 780);
  let yy = ly + 110 + (1 - titleIn) * 18;
  for (const line of [...nameLines, 'получил обновления']) {
    c.font = `800 ${size}px ${FONT}`; c.fillText(line, lx, yy); yy += size + 14;
  }
  c.globalAlpha = 1;
}

export function drawFrame(c, t, scene) {
  background(c);
  drawTitle(c, t, scene);
  drawPhone(c, scene);
  drawScreen(c, t, scene);
}
