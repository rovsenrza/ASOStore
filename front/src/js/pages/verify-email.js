import { boot, renderError } from '../app.js';
import { bindForm, safeNext } from '../forms.js';

const { api, t } = boot();
const form = document.querySelector('#verify-form');
const resend = document.querySelector('#verify-resend');
const resendStatus = document.querySelector('#verify-resend-status');
const done = () => location.assign(safeNext('/activate.html'));
let timer = null;

function countdown(seconds) {
  clearInterval(timer);
  let left = seconds;
  const tick = () => {
    resend.disabled = left > 0;
    resendStatus.textContent = left > 0 ? t('verifyEmail.resendIn', { seconds: left }) : '';
    left -= 1;
    if (left < 0) clearInterval(timer);
  };
  tick();
  timer = setInterval(tick, 1000);
}

bindForm(form, t, {
  submit: (data) => api.post('/auth/email/verify', { code: data.get('code') }),
  onSuccess: done,
});

resend.addEventListener('click', async () => {
  resend.disabled = true;
  try {
    const { data } = await api.post('/auth/email/resend');
    countdown(data.resend_after);
    resendStatus.textContent = t('verifyEmail.sent');
  } catch (error) {
    if (error?.details?.retry_after) countdown(error.details.retry_after);
    else renderError(form.querySelector('.form-status'), t, error);
  }
});

(async () => {
  try {
    const { data: user } = await api.get('/auth/me');
    if (user.email_verified) {
      done();
      return;
    }
    document.querySelector('#verify-lead').textContent = t('verifyEmail.lead', { email: user.email });
    // A code was just sent at registration.
    countdown(60);
  } catch (error) {
    if (error?.code === 'UNAUTHENTICATED') location.assign(`/login.html?next=${encodeURIComponent('/verify-email.html')}`);
    else renderError(form.querySelector('.form-status'), t, error);
  }
})();
