/**
 * Phone dock with the chosen package: shows once the hero is gone, hides while the
 * packages block itself is on screen, and follows the selected term.
 */
export function setupDock() {
  const dock = document.querySelector('[data-dock]');
  const hero = document.querySelector('[data-hero]');
  const pricing = document.querySelector('#pricing');
  if (!dock || !hero || !pricing) return;
  dock.hidden = false;
  let pastHero = false;
  let onPricing = false;
  const update = () => dock.classList.toggle('is-away', !pastHero || onPricing);

  new IntersectionObserver(([entry]) => { pastHero = !entry.isIntersecting; update(); }).observe(hero);
  new IntersectionObserver(([entry]) => { onPricing = entry.isIntersecting; update(); }, { threshold: .15 }).observe(pricing);
  update();

  document.addEventListener('plan-change', (event) => {
    const { value, term, total } = event.detail;
    dock.querySelector('[data-dock-term]').textContent = term;
    dock.querySelector('[data-dock-price]').textContent = `${total.toLocaleString('ru-RU')} ₽`;
    dock.querySelector('[data-dock-cta]').href = `/activate.html?plan=${value}`;
  });
}
