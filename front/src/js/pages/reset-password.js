import { boot } from '../app.js';
import { bindForm, showSuccess } from '../forms.js';

const { api, t } = boot();
const form = document.querySelector('#reset-form');
const params = new URLSearchParams(location.search);
const token = params.get('token');
const email = params.get('email');

if (!token || !email) {
  const notice = document.createElement('div');
  notice.className = 'notice notice--error';
  notice.setAttribute('role', 'alert');
  notice.innerHTML = '<p></p><a class="text-link" href="/forgot-password.html"></a>';
  notice.querySelector('p').textContent = t('reset.invalidLink');
  notice.querySelector('a').textContent = t('reset.requestNew');
  form.replaceChildren(notice);
} else {
  form.elements.namedItem('token').value = token;
  form.elements.namedItem('email').value = email;

  bindForm(form, t, {
    submit: (data) => api.post('/auth/password/reset', {
      token: data.get('token'),
      email: data.get('email'),
      password: data.get('password'),
    }),
    onSuccess: () => {
      showSuccess(form, t('reset.doneTitle'), t('reset.done'));
      const link = document.createElement('a');
      link.className = 'text-link';
      link.href = '/login.html';
      link.textContent = t('reset.toLogin');
      form.querySelector('.notice').append(link);
    },
  });
}
