/**
 * The packages block (homepage and pricing page): the chosen term drives the offer card.
 * The HTML already shows the default term, so the page reads correctly before this runs.
 */
const MONTHLY = 590;
const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const rub = (value) => `${value.toLocaleString('ru-RU')} ₽`;

function countTo(node, to) {
  const from = Number(node.textContent.replace(/\D/g, '')) || to;
  if (reduceMotion() || from === to) {
    node.textContent = to.toLocaleString('ru-RU');
    return;
  }
  const start = performance.now();
  const duration = 520;
  const step = (now) => {
    const t = Math.min(1, (now - start) / duration);
    const eased = 1 - (1 - t) ** 4;
    node.textContent = Math.round(from + (to - from) * eased).toLocaleString('ru-RU');
    if (t < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}

export function setupPackages(root = document) {
  const block = root.querySelector('[data-packages]');
  if (!block) return null;
  const field = (name) => block.querySelector(`[data-offer-${name}]`);

  const apply = (input) => {
    const months = Number(input.dataset.months);
    const total = Number(input.dataset.total);
    const full = MONTHLY * months;
    const saving = Math.round((1 - total / full) * 100);

    field('term').textContent = input.dataset.term;
    countTo(field('total'), total);
    field('monthly').textContent = months === 1 ? 'срок: 1 месяц' : `${months === 12 ? '≈' : ''}${rub(Math.round(total / months))} в месяц`;
    field('save').hidden = saving <= 0;
    field('save').textContent = `−${saving}%`;
    field('was').hidden = saving <= 0;
    field('was').innerHTML = `вместо <s>${rub(full)}</s> при оплате по месяцу`;
    const cta = field('cta');
    cta.href = `/activate.html?plan=${input.value}`;
    cta.textContent = `Выбрать ${input.dataset.term}`;
    block.dispatchEvent(new CustomEvent('plan-change', { bubbles: true, detail: { value: input.value, term: input.dataset.term, total } }));
  };

  block.addEventListener('change', (event) => {
    if (event.target.name === 'plan') apply(event.target);
  });
  const checked = block.querySelector('input[name="plan"]:checked');
  if (checked) apply(checked);
  return block;
}
