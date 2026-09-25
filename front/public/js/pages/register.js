import { boot } from '../app.js';
import { bindForm } from '../forms.js';

const { api, t } = boot();

bindForm(document.querySelector('#register-form'), t, {
  submit: (data) => api.post('/auth/register', {
    name: data.get('name'),
    email: data.get('email'),
    password: data.get('password'),
  }),
  onSuccess: () => location.assign('/activate.html'),
});
