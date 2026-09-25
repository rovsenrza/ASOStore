/**
 * Minimal dictionary lookup. Static page copy lives in HTML; everything the
 * scripts render (states, errors, buttons) goes through a dictionary so a
 * second language only needs a new dictionary file.
 */
export function createTranslator(dictionary) {
  const lookup = (key) => key.split('.').reduce((node, part) => node?.[part], dictionary);

  function t(key, params = {}) {
    const value = lookup(key);
    if (typeof value !== 'string') {
      console.warn(`Missing translation: ${key}`);
      return key;
    }
    return value.replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? `{${name}}`));
  }

  t.has = (key) => typeof lookup(key) === 'string';

  /** A list of strings, e.g. numbered steps. */
  t.list = (key) => {
    const value = lookup(key);
    return Array.isArray(value) ? value : [];
  };

  /** Copy for an ApiError: the client's own text for the code, else the server fallback. */
  t.error = (error) => {
    if (t.has(`errors.${error?.code}`)) return t(`errors.${error.code}`);
    return error?.message || t('errors.INTERNAL');
  };

  return t;
}
