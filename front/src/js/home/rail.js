/**
 * Previous/next buttons for the category rail when it is a plain sideways scroller
 * (phones, reduced motion). On desktops with motion the rail pins and scroll moves it.
 */
export function setupRail() {
  const viewport = document.querySelector('[data-rail-viewport]');
  const prev = document.querySelector('[data-rail-prev]');
  const next = document.querySelector('[data-rail-next]');
  if (!viewport || !prev || !next) return;

  const step = () => viewport.querySelector('.rail__panel')?.getBoundingClientRect().width ?? viewport.clientWidth * 0.8;
  const behavior = () => (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth');
  const update = () => {
    prev.disabled = viewport.scrollLeft <= 4;
    next.disabled = viewport.scrollLeft + viewport.clientWidth >= viewport.scrollWidth - 4;
  };

  prev.addEventListener('click', () => viewport.scrollBy({ left: -step(), behavior: behavior() }));
  next.addEventListener('click', () => viewport.scrollBy({ left: step(), behavior: behavior() }));
  viewport.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update, { passive: true });
  update();
}
