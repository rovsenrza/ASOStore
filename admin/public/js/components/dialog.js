import { el } from '../app.js';

/**
 * Modal panel for detail views and forms. Returns controls to replace the
 * body and close it; the dialog removes itself when closed.
 */
export function openDialog(t, { title, body, wide = false, onClose }) {
  const heading = el('h2', { id: `dialog-${Date.now()}` }, title);
  const close = el('button', { type: 'button', className: 'dialog-close', ariaLabel: t('common.close') }, '×');
  const content = el('div', { className: 'panel-dialog__body' }, body);
  const dialog = el('dialog', { className: `panel-dialog${wide ? ' panel-dialog--wide' : ''}` },
    el('header', { className: 'panel-dialog__header' }, heading, close),
    content);
  dialog.setAttribute('aria-labelledby', heading.id);

  close.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => {
    dialog.remove();
    onClose?.();
  });

  document.body.append(dialog);
  dialog.showModal();

  return {
    dialog,
    setBody: (...nodes) => content.replaceChildren(...nodes),
    close: () => dialog.close(),
  };
}
