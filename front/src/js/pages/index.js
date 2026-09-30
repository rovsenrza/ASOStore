import { boot, renderError, renderLoading, renderStage } from '../app.js';
import { showCatalogCount } from '../home/catalog-count.js';
import { setupDock } from '../home/dock.js';
import { setupRail } from '../home/rail.js';
import { mountStage } from '../home/stage/index.js';
import { setupSteps } from '../home/steps.js';
import { setupWall } from '../home/wall.js';
import { setupPackages } from '../packages.js';

const { api, t } = boot();

// Works without the motion layer: packages, steps, filters, dock, live catalog size.
setupPackages();
setupSteps();
setupRail();
setupDock();
showCatalogCount(api);
const stage = mountStage();

// The motion layer (GSAP) loads after the first paint, and not at all with reduced motion.
let motion = null;
setupWall(() => motion);
if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  let started = false;
  const load = () => {
    if (started) return;
    started = true;
    import('../home/motion.js').then(({ startMotion }) => { motion = startMotion({ stage }); });
  };
  // Keep the first paint free of GSAP work. Interaction brings it in immediately;
  // a visitor who stays at the hero gets the motion after a short delay.
  for (const type of ['scroll', 'wheel', 'pointerdown', 'keydown']) {
    window.addEventListener(type, load, { once: true, passive: true });
  }
  const later = () => setTimeout(load, 5000);
  if (document.readyState === 'complete') later(); else window.addEventListener('load', later, { once: true });
}

// Status check at the end of the page.
const button = document.querySelector('#status-check');
const result = document.querySelector('#status-result');

async function checkStatus() {
  button.disabled = true;
  renderLoading(result, t);
  try {
    const { data } = await api.get('/storefront/status');
    renderStage(result, t, data.stage, { tone: 'status-stage' });
  } catch (error) {
    renderError(result, t, error, checkStatus);
  } finally {
    button.disabled = false;
  }
}

button.addEventListener('click', checkStatus);
