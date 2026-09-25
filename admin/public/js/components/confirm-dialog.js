/**
 * Confirmation for sensitive operator actions. With requireReason, the reason
 * is returned so it can be sent to the API and land in the audit log.
 *
 * @returns {Promise<{confirmed: boolean, reason: string}>}
 */
export function confirmAction(t, { title, message, confirmLabel, danger = false, requireReason = false }) {
  return new Promise((resolve) => {
    const dialog = document.createElement('dialog');
    dialog.className = 'confirm-dialog';
    dialog.innerHTML = `
      <form method="dialog">
        <h2></h2>
        <p></p>
        <label hidden>${t('common.reason')}<textarea name="reason" maxlength="500"></textarea></label>
        <div class="actions">
          <button class="button" value="cancel" type="submit" formnovalidate>${t('common.cancel')}</button>
          <button class="button ${danger ? 'button--danger' : 'button--primary'}" value="confirm" type="submit"></button>
        </div>
      </form>`;
    dialog.querySelector('h2').textContent = title;
    dialog.querySelector('p').textContent = message;
    dialog.querySelector('[value="confirm"]').textContent = confirmLabel ?? t('common.confirm');

    const reason = dialog.querySelector('textarea');
    if (requireReason) {
      reason.closest('label').hidden = false;
      reason.required = true;
    }

    dialog.addEventListener('close', () => {
      resolve({ confirmed: dialog.returnValue === 'confirm', reason: reason.value.trim() });
      dialog.remove();
    });

    document.body.append(dialog);
    dialog.showModal();
  });
}
