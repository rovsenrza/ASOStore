import { renderError } from './app.js';

/**
 * Submits a form through the API with a busy state, inline field errors
 * (from VALIDATION_FAILED details) and a general error notice otherwise.
 */
export function bindForm(form, t, { submit, onSuccess }) {
  const button = form.querySelector('[type="submit"]');
  const status = form.querySelector('.form-status');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearErrors(form, status);
    if (!form.reportValidity()) return;

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
      const result = await submit(new FormData(form));
      await onSuccess(result);
    } catch (error) {
      showErrors(form, status, t, error);
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
    }
  });
}

function clearErrors(form, status) {
  status?.replaceChildren();
  form.querySelectorAll('.field-error').forEach((node) => node.remove());
  form.querySelectorAll('[aria-invalid]').forEach((input) => {
    input.removeAttribute('aria-invalid');
    const hint = input.dataset.hint;
    if (hint) input.setAttribute('aria-describedby', hint);
    else input.removeAttribute('aria-describedby');
  });
}

function showErrors(form, status, t, error) {
  let first = null;

  for (const [name, messages] of Object.entries(error?.details?.fields ?? {})) {
    const input = form.elements.namedItem(name);
    if (!(input instanceof HTMLElement) || input.type === 'hidden') continue;

    const message = document.createElement('p');
    message.className = 'field-error';
    message.id = `${input.id}-error`;
    message.textContent = messages[0];
    input.closest('.field')?.append(message);

    input.dataset.hint = input.getAttribute('aria-describedby') ?? '';
    input.setAttribute('aria-describedby', [message.id, input.dataset.hint].filter(Boolean).join(' '));
    input.setAttribute('aria-invalid', 'true');
    first ??= input;
  }

  if (first) {
    first.focus();
  } else if (status) {
    renderError(status, t, error);
  }
}

/**
 * A redirect target from ?next=, limited to same-site paths.
 */
export function safeNext(fallback) {
  const next = new URLSearchParams(location.search).get('next');
  return next && next.startsWith('/') && !next.startsWith('//') ? next : fallback;
}

export function showSuccess(form, title, text) {
  const notice = document.createElement('div');
  notice.className = 'notice notice--ok';
  notice.setAttribute('role', 'status');
  const heading = document.createElement('b');
  heading.textContent = title;
  const body = document.createElement('p');
  body.textContent = text;
  notice.append(heading, body);
  form.replaceChildren(notice);
}
