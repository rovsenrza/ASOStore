import { boot, el, errorNotice, prepareImage } from '../app.js';
import { toast } from '../components/toast.js';

// What the Ru App Store app shows on its Home tab: the editor's banner and its order
// (featured_rank). The lists below the banner are built from real data by the feed.
const { api, t, can } = await boot();
const manage = can('catalog.manage');
const box = document.querySelector('#home');

let apps = [];
let banner = [];      // app ids, in banner order
let saved = [];

async function load() {
  try {
    const { data } = await api.get('/admin/apps?visibility=PUBLISHED&per_page=100');
    apps = data;
    banner = data.filter((app) => app.featured_rank != null)
      .sort((a, b) => a.featured_rank - b.featured_rank)
      .map((app) => app.id);
    saved = [...banner];
    render();
  } catch (error) {
    box.replaceChildren(errorNotice(t, error, load));
  }
}

const byId = (id) => apps.find((app) => app.id === id);
const dirty = () => banner.join() !== saved.join();

function icon(app) {
  return app.icon_url ? el('img', { className: 'thumb', src: app.icon_url, alt: '' }) : el('span', { className: 'thumb thumb--empty' }, app.name.slice(0, 1));
}

function row(app, ...actions) {
  const inBanner = banner.includes(app.id);
  return el('li', { className: 'home-row' },
    icon(app),
    inBanner ? bannerPreview(app) : null,
    el('span', { className: 'home-row__name' }, el('b', {}, app.name),
      inBanner && !app.banner_url ? el('small', { className: 'muted' }, t('home.noBanner')) : null),
    manage ? el('span', { className: 'button-row' }, inBanner ? bannerUpload(app) : null, ...actions) : null);
}

function bannerPreview(app) {
  return app.banner_url
    ? el('img', { className: 'home-row__banner', src: app.banner_url, alt: '' })
    : el('span', { className: 'home-row__banner thumb thumb--empty' }, '16:10');
}

// Sets the card's picture without leaving the page (it is also on the app's page).
function bannerUpload(app) {
  const input = el('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp', hidden: true });
  input.addEventListener('change', async () => {
    const [file] = input.files;
    if (!file) return;
    try {
      const body = new FormData();
      body.append('banner', await prepareImage(file, { maxBytes: 8_000_000, maxWidth: 2400 }));
      const { data } = await api.post(`/admin/apps/${app.id}/banner`, body);
      app.banner_url = data.banner_url;
      toast(t('home.bannerUploaded'), { tone: 'ok' });
      render();
    } catch (error) {
      toast(t.error(error), { tone: 'error' });
    }
  });
  return el('span', {}, input,
    el('button', { type: 'button', className: 'button', onclick: () => input.click() }, t(app.banner_url ? 'home.replaceBanner' : 'home.uploadBanner')));
}

function move(index, delta) {
  const [id] = banner.splice(index, 1);
  banner.splice(index + delta, 0, id);
  render();
}

function add(id) {
  banner.push(id);
  render();
}

function render() {
  const inBanner = banner.map(byId).filter(Boolean);
  const others = apps.filter((app) => !banner.includes(app.id));

  const bannerCard = el('section', { className: 'card stack' },
    el('h2', {}, t('home.bannerTitle')),
    el('p', {}, t('home.bannerHint')),
    inBanner.length
      ? el('ol', { className: 'home-list' }, ...inBanner.map((app, index) => row(app,
        el('button', { type: 'button', className: 'button', disabled: index === 0, onclick: () => move(index, -1) }, t('home.up')),
        el('button', { type: 'button', className: 'button', disabled: index === inBanner.length - 1, onclick: () => move(index, 1) }, t('home.down')),
        el('button', { type: 'button', className: 'button', onclick: () => { banner.splice(index, 1); render(); } }, t('home.remove')))))
      : el('p', { className: 'notice' }, t('home.bannerEmpty')),
    manage ? el('div', { className: 'button-row' },
      el('button', { type: 'button', className: 'button button--primary', disabled: !dirty(), onclick: save }, t('home.save')),
      dirty() ? el('span', { className: 'muted' }, t('home.unsaved')) : null) : null);

  const otherCard = el('section', { className: 'card stack' },
    el('h2', {}, t('home.otherTitle')),
    el('p', {}, t('home.otherHint')),
    others.length
      ? el('ul', { className: 'home-list' }, ...others.map((app) => row(app,
        el('button', { type: 'button', className: 'button', onclick: () => add(app.id) }, t('home.add')))))
      : el('p', { className: 'muted' }, t('home.otherEmpty')));

  box.replaceChildren(el('div', { className: 'stack' }, bannerCard, otherCard));
}

async function save(event) {
  event.target.disabled = true;
  // Only apps whose position changed are written: 1…n for the banner, null for removed ones.
  const changes = [
    ...banner.map((id, index) => [id, index + 1]),
    ...saved.filter((id) => !banner.includes(id)).map((id) => [id, null]),
  ].filter(([id, rank]) => byId(id)?.featured_rank !== rank);
  try {
    for (const [id, rank] of changes) {
      await api.patch(`/admin/apps/${id}`, { featured_rank: rank });
    }
    toast(t('home.saved'), { tone: 'ok' });
    await load();
  } catch (error) {
    toast(t.error(error), { tone: 'error' });
    await load();
  }
}

await load();
