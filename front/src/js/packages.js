/**
 * The packages block (homepage, purchase page): the chosen term drives the offer card and
 * the «Купить» link into the Telegram bot, where the order for that term opens.
 * The HTML already shows the default term and prices, so the page reads correctly before
 * this runs and when the live prices (GET /store/offer) cannot be loaded.
 */
const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const rub = (value) => `${value.toLocaleString('ru-RU')} ₽`;
// Links from before the plan keys matched the bot's.
const LEGACY_PLANS = { '1m': 'month1', '6m': 'month6', '12m': 'month12' };

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

const monthlyOf = (input) => Number(input.dataset.total) / Number(input.dataset.months);

/** «295 ₽/мес · −50%», «≈197 ₽/мес»: approximate when the total does not divide evenly. */
function monthlyLabel(node, input, saving) {
  const months = Number(input.dataset.months);
  const total = Number(input.dataset.total);
  const approx = total % months === 0 ? '' : '≈';
  node.textContent = `${approx}${rub(Math.round(total / months))}/мес`;
  if (saving > 0) {
    const badge = document.createElement('span');
    badge.className = 'plan-option__save';
    badge.textContent = `−${saving}%`;
    node.append(' · ', badge);
  }
}

export function setupPackages(root = document) {
  const block = root.querySelector('[data-packages]');
  if (!block) return null;
  const field = (name) => block.querySelector(`[data-offer-${name}]`);
  const inputs = () => [...block.querySelectorAll('input[name="plan"]')].filter((input) => !input.closest('label').hidden);
  // Savings are measured against paying the shortest term's monthly price (as the bot does).
  const baseMonthly = () => monthlyOf(inputs().reduce((a, b) => (Number(a.dataset.months) <= Number(b.dataset.months) ? a : b)));
  const savingOf = (input) => Math.max(0, Math.round((1 - monthlyOf(input) / baseMonthly()) * 100));

  const apply = (input) => {
    const months = Number(input.dataset.months);
    const total = Number(input.dataset.total);
    const full = Math.round(baseMonthly() * months);
    const saving = savingOf(input);

    field('term').textContent = input.dataset.term;
    countTo(field('total'), total);
    field('monthly').textContent = months === 1 ? 'срок: 1 месяц' : `${total % months === 0 ? '' : '≈'}${rub(Math.round(total / months))} в месяц`;
    field('save').hidden = saving <= 0;
    field('save').textContent = `−${saving}%`;
    field('was').hidden = saving <= 0;
    field('was').innerHTML = `вместо <s>${rub(full)}</s> при оплате по месяцу`;
    const cta = field('cta');
    cta.href = input.dataset.buyUrl;
    field('cta-label').textContent = `Или заказать ${input.dataset.term} в Telegram`;
    field('pay-label').textContent = `Оплатить ${rub(total)}`;
    block.dispatchEvent(new CustomEvent('plan-change', { bubbles: true, detail: { value: input.value, term: input.dataset.term, total, buyUrl: input.dataset.buyUrl } }));
  };

  block.addEventListener('change', (event) => {
    if (event.target.name === 'plan') apply(event.target);
  });

  // Card/SBP payment on the site is not connected yet: the button answers with a notice.
  // When the payment system is live, create the payment for the chosen plan here and
  // send the customer to its page instead of showing the notice.
  const payButton = field('pay');
  const payNotice = field('pay-notice');
  payButton?.addEventListener('click', () => {
    payNotice.hidden = false;
    payNotice.scrollIntoView({ block: 'nearest', behavior: reduceMotion() ? 'auto' : 'smooth' });
  });

  // ?plan=month12 (or the older 12m) chooses the term.
  const wanted = new URLSearchParams(location.search).get('plan');
  const preselected = wanted && block.querySelector(`input[name="plan"][value="${CSS.escape(LEGACY_PLANS[wanted] ?? wanted)}"]`);
  if (preselected) preselected.checked = true;
  const checked = block.querySelector('input[name="plan"]:checked');
  if (checked) apply(checked);

  /** Live prices and links from the store; terms the store no longer sells are hidden. */
  block.applyOffer = (offer) => {
    if (!offer?.plans?.length) return;
    const plans = new Map(offer.plans.map((plan) => [plan.id, plan]));
    for (const input of block.querySelectorAll('input[name="plan"]')) {
      const plan = plans.get(input.value);
      input.closest('label').hidden = !plan;
      if (!plan) continue;
      input.dataset.total = String(plan.price);
      input.dataset.months = String(plan.months);
      if (plan.buy_url) input.dataset.buyUrl = plan.buy_url;
    }
    for (const input of inputs()) {
      const label = input.closest('label');
      label.querySelector('[data-plan-total]').textContent = rub(Number(input.dataset.total));
      monthlyLabel(label.querySelector('[data-plan-monthly]'), input, savingOf(input));
    }
    const current = block.querySelector('input[name="plan"]:checked');
    const visible = current && !current.closest('label').hidden ? current : inputs()[0];
    if (visible) {
      visible.checked = true;
      apply(visible);
    }
  };

  return block;
}
