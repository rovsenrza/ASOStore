/**
 * "How it works": the step in the middle of the viewport is the active one, and the phone
 * beside the list shows that step's screen. Without this script every step stays readable.
 */
export function setupSteps() {
  const steps = [...document.querySelectorAll('[data-step]')];
  const screens = [...document.querySelectorAll('[data-screen]')];
  if (!steps.length) return;
  document.querySelector('[data-steps]').classList.add('js-steps');

  const activate = (index) => {
    steps.forEach((step, i) => step.classList.toggle('is-active', i === index));
    screens.forEach((screen, i) => screen.classList.toggle('is-active', i === index));
  };

  const observer = new IntersectionObserver((entries) => {
    const visible = entries.filter((entry) => entry.isIntersecting);
    if (visible.length) activate(Number(visible.at(-1).target.dataset.step));
  }, { rootMargin: '-45% 0px -45% 0px' });
  steps.forEach((step) => observer.observe(step));
}
