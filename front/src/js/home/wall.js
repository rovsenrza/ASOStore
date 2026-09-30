/**
 * Catalog wall filters. Once the motion layer is loaded, icons glide to their new places
 * (GSAP Flip); before that, or with reduced motion, the wall simply re-flows.
 */
export function setupWall(getMotion) {
  const wall = document.querySelector('[data-wall]');
  const chips = [...document.querySelectorAll('[data-filter]')];
  if (!wall || !chips.length) return;
  const items = [...wall.children];

  chips.forEach((chip) => chip.addEventListener('click', () => {
    const group = chip.dataset.filter;
    chips.forEach((other) => other.setAttribute('aria-pressed', String(other === chip)));
    const motion = getMotion();
    const state = motion?.Flip.getState(items);
    items.forEach((item) => { item.hidden = group !== 'all' && item.dataset.group !== group; });
    if (!motion) return;
    const { gsap, Flip } = motion;
    Flip.from(state, {
      duration: .7,
      ease: 'expo.out',
      absolute: true,
      onEnter: (entering) => gsap.fromTo(entering, { opacity: 0, scale: .7 }, { opacity: 1, scale: 1, duration: .5, ease: 'expo.out' }),
      onLeave: (leaving) => gsap.to(leaving, { opacity: 0, scale: .7, duration: .3 }),
    });
  }));
}
