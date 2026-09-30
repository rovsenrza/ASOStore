import { pickTier } from './tier.js';

/**
 * Starts the WebGL hero on devices that can carry it. Three.js and the scene load only
 * after the page has finished loading and the browser is idle (on phones, after the first
 * touch or scroll), so they never compete with the first paint; elsewhere the still stays.
 */
export function mountStage() {
  const stage = document.querySelector('[data-stage]');
  const canvas = stage?.querySelector('[data-stage-canvas]');
  if (!canvas) return null;
  const tier = pickTier();
  if (tier === 'none') return null;

  let instance = null;
  let progress = 0;
  const ready = new Promise((resolve) => {
    let bootStarted = false;
    const boot = async () => {
      if (bootStarted) return;
      bootStarted = true;
      try {
        const [{ createStage }, icons] = await Promise.all([
          import('./scene.js'),
          fetch('/assets/icons/icons.json').then((response) => response.json()),
        ]);
        instance = await createStage({
          canvas,
          tier,
          icons,
          atlasUrl: '/assets/icons/atlas.webp',
          onSlow: () => stage.classList.remove('is-webgl'),
        });
        instance.setProgress(progress);
        stage.classList.add('is-webgl');
      } catch {
        // The rendered poster stays visible when WebGL cannot start.
      }
      resolve(instance);
    };
    let idleScheduled = false;
    const idle = () => {
      if (idleScheduled) return;
      idleScheduled = true;
      if ('requestIdleCallback' in window) requestIdleCallback(boot, { timeout: 2500 });
      else setTimeout(boot, 1200);
    };
    // Phones wait for the visitor's first touch or scroll: the page's first seconds stay light.
    const start = tier === 'low'
      ? () => ['scroll', 'touchstart', 'pointerdown'].forEach((type) => window.addEventListener(type, idle, { once: true, passive: true }))
      : idle;
    if (document.readyState === 'complete') start(); else window.addEventListener('load', start, { once: true });
  });

  return {
    ready,
    setProgress(value) {
      progress = value;
      instance?.setProgress(value);
    },
  };
}
