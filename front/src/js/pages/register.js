import { boot } from '../app.js';
import { bindForm, keepNextOnLinks, safeNext } from '../forms.js';

const { api, t } = boot();
keepNextOnLinks();

bindForm(document.querySelector('#register-form'), t, {
  submit: (data) => api.post('/auth/register', {
    name: data.get('name'),
    email: data.get('email'),
    password: data.get('password'),
  }),
  // ?next= (e.g. back to the purchase page) survives the email confirmation step.
  onSuccess: () => {
    const next = safeNext(null);
    location.assign(next ? `/verify-email.html?next=${encodeURIComponent(next)}` : '/verify-email.html');
  },
});
