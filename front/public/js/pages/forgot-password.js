import { boot } from '../app.js';
import { bindForm, showSuccess } from '../forms.js';

const { api, t } = boot();
const form = document.querySelector('#forgot-form');

bindForm(form, t, {
  submit: (data) => api.post('/auth/password/forgot', { email: data.get('email') }),
  onSuccess: () => showSuccess(form, t('forgot.title'), t('forgot.sent')),
});
