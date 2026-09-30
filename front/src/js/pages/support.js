import { boot } from '../app.js';
import { bindForm } from '../forms.js';

// Support requests (IMPLEMENTATION_PLAN P8-WEB-01). Works signed out: a customer
// whose app stopped working must still be able to reach us.
const { api, t } = boot();
const form = document.querySelector('#support-form');
const emailField = document.querySelector('#support-email-field');

api.get('/auth/me').then(() => {
  emailField.hidden = true;
  emailField.querySelector('input').required = false;
}).catch(() => { /* signed out: the email field stays */ });

bindForm(form, t, {
  submit: (data) => {
    const body = Object.fromEntries([...data.entries()].filter(([, value]) => value !== ''));
    return api.post('/support/tickets', body);
  },
  onSuccess: ({ data }) => {
    const done = document.createElement('div');
    done.className = 'notice notice--ok';
    const title = document.createElement('b');
    title.textContent = t('support.sentTitle');
    const text = document.createElement('p');
    text.textContent = t('support.sent', { id: data.id });
    done.append(title, text);
    form.replaceWith(done);
  },
});
