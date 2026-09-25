import { boot, el, errorNotice } from '../app.js';

const { api, t } = await boot({ requireAuth: false });

const passwordForm = document.querySelector('#password-step');
const totpForm = document.querySelector('#totp-step');
const enrollment = document.querySelector('#enrollment');
const status = document.querySelector('#login-status');

function next() {
  const target = new URLSearchParams(location.search).get('next');
  return target && target.startsWith('/admin/') && !target.startsWith('//') ? target : 'index.html';
}

async function submitting(form, run) {
  const button = form.querySelector('[type="submit"]');
  status.replaceChildren();
  button.disabled = true;
  try {
    await run();
  } catch (error) {
    if (error?.code === 'TOTP_REQUIRED') {
      showPasswordStep();
      status.replaceChildren(errorNotice(t, { ...error, message: t('login.expired'), code: 'LOGIN_EXPIRED' }));
    } else {
      status.replaceChildren(errorNotice(t, error));
    }
  } finally {
    button.disabled = false;
  }
}

function showPasswordStep() {
  totpForm.hidden = true;
  enrollment.replaceChildren();
  passwordForm.hidden = false;
  passwordForm.querySelector('input[name="password"]').value = '';
}

function showEnrollment({ secret, qr_svg: svg }) {
  const image = el('img', {
    className: 'qr',
    alt: t('login.enrollTitle'),
    src: `data:image/svg+xml;base64,${btoa(unescape(encodeURIComponent(svg)))}`,
    width: 220,
    height: 220,
  });
  enrollment.replaceChildren(
    el('h2', {}, t('login.enrollTitle')),
    el('p', {}, t('login.enrollText')),
    image,
    el('p', { className: 'secret' }, `${t('login.secret')}: `, el('code', {}, secret.match(/.{1,4}/g).join(' '))),
  );
}

passwordForm.addEventListener('submit', (event) => {
  event.preventDefault();
  if (!passwordForm.reportValidity()) return;
  const data = new FormData(passwordForm);

  submitting(passwordForm, async () => {
    const { data: step } = await api.post('/admin/auth/login', { email: data.get('email'), password: data.get('password') });
    passwordForm.hidden = true;
    if (step.next_step === 'totp_enrollment') showEnrollment(step.enrollment);
    totpForm.hidden = false;
    totpForm.querySelector('input').focus();
  });
});

totpForm.addEventListener('submit', (event) => {
  event.preventDefault();
  if (!totpForm.reportValidity()) return;
  const code = new FormData(totpForm).get('code');

  submitting(totpForm, async () => {
    await api.post('/admin/auth/totp', { code });
    location.assign(next());
  });
});
