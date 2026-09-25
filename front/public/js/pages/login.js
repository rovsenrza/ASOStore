import { boot } from '../app.js';
import { bindForm, safeNext } from '../forms.js';

const { api, t } = boot();

bindForm(document.querySelector('#login-form'), t, {
  submit: (data) => api.post('/auth/login', { email: data.get('email'), password: data.get('password') }),
  onSuccess: () => location.assign(safeNext('/account.html')),
});
