/**
 * The packages block (homepage, purchase page): the chosen term drives the offer card,
 * the «Оплатить» button and the link into the Telegram bot, where the order for that term opens.
 * The HTML already shows the default term and prices, so the page reads correctly before
 * this runs and when the live prices (GET /store/offer) cannot be loaded.
 * With online payment connected (offer.online_payment), «Оплатить» opens Platega's page for
 * the signed-in account (POST /store/checkout); otherwise it shows the «unavailable» notice.
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

export function setupPackages(root = document, { api = null } = {}) {
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
    payLabel = `Оплатить ${rub(total)}`;
    if (!payButton?.disabled) field('pay-label').textContent = payLabel;
    block.dispatchEvent(new CustomEvent('plan-change', { bubbles: true, detail: { value: input.value, term: input.dataset.term, total, buyUrl: input.dataset.buyUrl } }));
  };

  block.addEventListener('change', (event) => {
    if (event.target.name === 'plan') apply(event.target);
  });

  const payButton = field('pay');
  const payNotice = field('pay-notice');
  const unavailableNotice = payNotice?.innerHTML ?? '';
  // null until the offer says: a click before that (slow network) still tries to pay,
  // and the server answers SERVICE_UNAVAILABLE when online payment is off.
  let online = null;
  let payLabel = '';

  /** The «unavailable» text from the HTML, or a failure with a link to support. */
  const showNotice = (title = null, text = '') => {
    if (!payNotice) return;
    if (title) {
      const heading = document.createElement('b');
      heading.textContent = title;
      const body = document.createElement('p');
      const support = document.createElement('a');
      support.href = '/support.html#contact-title';
      support.textContent = 'Написать в поддержку';
      body.append(`${text} `, support);
      payNotice.replaceChildren(heading, body);
    } else {
      payNotice.innerHTML = unavailableNotice;
    }
    payNotice.hidden = false;
    payNotice.scrollIntoView({ block: 'nearest', behavior: reduceMotion() ? 'auto' : 'smooth' });
  };

  const setBusy = (busy) => {
    if (!payButton) return;
    payButton.disabled = busy;
    if (busy) payButton.setAttribute('aria-busy', 'true');
    else payButton.removeAttribute('aria-busy');
    field('pay-label').textContent = busy ? 'Открываем оплату…' : payLabel;
  };

  /** Opens the order and goes to Platega; signs in or confirms the email first when needed. */
  const checkout = async () => {
    const plan = block.querySelector('input[name="plan"]:checked')?.value;
    if (!plan) return;
    const back = `/buy.html?plan=${encodeURIComponent(plan)}#pricing`;
    setBusy(true);
    try {
      const { data } = await api.post('/store/checkout', { plan }, {
        idempotencyKey: `checkout-${globalThis.crypto?.randomUUID?.() ?? Date.now()}`,
      });
      location.assign(data.payment_url);
      return;
    } catch (error) {
      if (error?.code === 'UNAUTHENTICATED' || error?.code === 'SESSION_EXPIRED') {
        location.assign(`/register.html?next=${encodeURIComponent(back)}`);
        return;
      }
      if (error?.code === 'EMAIL_NOT_VERIFIED') {
        location.assign(`/verify-email.html?next=${encodeURIComponent(back)}`);
        return;
      }
      setBusy(false);
      const readable = error?.message && !['INTERNAL', 'NETWORK_ERROR', 'OFFLINE'].includes(error.code);
      showNotice('Не удалось открыть оплату', error?.code === 'RATE_LIMITED'
        ? 'Слишком много попыток подряд. Подождите немного и попробуйте снова.'
        : `${readable ? error.message : 'Нет связи с сервером.'} Попробуйте ещё раз через минуту.`);
    }
  };

  payButton?.addEventListener('click', () => {
    if (online !== false && api) {
      if (payNotice) payNotice.hidden = true;
      checkout();
    } else {
      showNotice();
    }
  });
  // Back from Platega's page with the browser's back button: the button works again.
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) setBusy(false);
  });

  // ?plan=month12 (or the older 12m) chooses the term.
  const wanted = new URLSearchParams(location.search).get('plan');
  const preselected = wanted && block.querySelector(`input[name="plan"][value="${CSS.escape(LEGACY_PLANS[wanted] ?? wanted)}"]`);
  if (preselected) preselected.checked = true;
  const checked = block.querySelector('input[name="plan"]:checked');
  if (checked) apply(checked);

  /** Live prices and links from the store; terms the store no longer sells are hidden. */
  block.applyOffer = (offer) => {
    if (typeof offer?.online_payment === 'boolean') online = offer.online_payment;
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
