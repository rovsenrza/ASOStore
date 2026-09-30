/**
 * Serves the shared API examples (docs/api/examples, published to /mock/ by
 * scripts/build-public.sh) in place of the network, so UI work can proceed
 * without a backend. Signing in and out is remembered for the tab, so flows
 * can be clicked through. Pages show a "demo data" badge while it is active.
 */

const STATE_KEY = 'storefront.mock.signedIn';

function signedIn() {
  try { return sessionStorage.getItem(STATE_KEY) === '1'; } catch { return false; }
}

function setSignedIn(value) {
  try {
    if (value) sessionStorage.setItem(STATE_KEY, '1');
    else sessionStorage.removeItem(STATE_KEY);
  } catch { /* storage unavailable: stay signed out */ }
}

const envelope = (data) => ({ data, meta: { request_id: 'mock' }, error: null });
const failure = (code, message) => ({ data: null, meta: { request_id: 'mock' }, error: { code, message, details: {} } });
const unauthenticated = () => ({ status: 401, json: failure('UNAUTHENTICATED', 'Войдите в аккаунт, чтобы продолжить.') });

// [method, path pattern, handler returning { status?, fixture? | json? }]
const ROUTES = [
  ['GET', /^\/health$/, () => ({ fixture: 'health.json' })],
  ['GET', /^\/storefront\/feed$/, () => ({ fixture: 'storefront-feed.json' })],
  ['GET', /^\/storefront\/status$/, () => ({ fixture: signedIn() ? 'storefront-status-ready.json' : 'storefront-status-signed-out.json' })],
  ['GET', /^\/apps$/, () => ({ fixture: 'apps-list.json' })],
  ['GET', /^\/apps\/[^/]+\/versions$/, () => ({ fixture: 'app-versions.json' })],
  ['GET', /^\/apps\/[^/]+$/, () => ({ fixture: 'app-detail.json' })],

  ['POST', /^\/auth\/(login|register)$/, () => { setSignedIn(true); return { fixture: 'auth-me.json' }; }],
  ['POST', /^\/auth\/logout$/, () => { setSignedIn(false); return { json: envelope(null) }; }],
  ['GET', /^\/auth\/me$/, () => (signedIn() ? { fixture: 'auth-me.json' } : unauthenticated())],
  ['POST', /^\/auth\/email\/verify$/, () => ({ fixture: 'auth-me.json' })],
  ['POST', /^\/auth\/email\/resend$/, () => ({ status: 202, json: envelope({ resend_after: 60 }) })],
  ['POST', /^\/auth\/password\/forgot$/, () => ({ status: 202, json: envelope({ accepted: true }) })],
  ['POST', /^\/auth\/password\/reset$/, () => ({ json: envelope({ reset: true }) })],
  ['POST', /^\/activation\/redeem$/, () => ({ status: 201, fixture: 'activation-redeem.json' })],
  ['GET', /^\/devices\/me$/, () => (signedIn() ? { fixture: 'devices-me.json' } : unauthenticated())],
  ['POST', /^\/storefront\/claims$/, () => ({ status: 201, json: envelope({ code: 'mock-claim', url: 'storefront://claim?code=mock-claim', expires_at: new Date(Date.now() + 600000).toISOString() }) })],
  ['GET', /^\/admin\/devices$/, () => ({ fixture: 'admin-devices.json' })],

  ['POST', /^\/admin\/auth\/login$/, () => ({ json: envelope({ next_step: 'totp', enrollment: null }) })],
  ['POST', /^\/admin\/auth\/totp$/, () => { setSignedIn(true); return { fixture: 'admin-me.json' }; }],
  ['POST', /^\/admin\/auth\/logout$/, () => { setSignedIn(false); return { json: envelope(null) }; }],
  ['GET', /^\/admin\/auth\/me$/, () => (signedIn() ? { fixture: 'admin-me.json' } : unauthenticated())],
  ['GET', /^\/admin\/users$/, () => ({ fixture: 'admin-users.json' })],
  ['GET', /^\/admin\/activation-codes$/, () => ({ fixture: 'admin-activation-codes.json' })],
  ['POST', /^\/admin\/activation-codes$/, () => ({
    status: 201,
    json: envelope({ batch_id: '01j9ex4mp1ebatch000000000a', codes: ['7K3M-Q9TX-2HWD-8RBN', 'M4PZ-6JQE-T1VC-XS9A'], count: 2 }),
  })],
  ['GET', /^\/admin\/audit-logs$/, () => ({ fixture: 'admin-audit-logs.json' })],
];

function delay(ms, signal) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(resolve, ms);
    signal?.addEventListener('abort', () => {
      clearTimeout(timer);
      reject(new DOMException('Aborted', 'AbortError'));
    });
  });
}

export function createMockTransport({ baseUrl = '/api/v1', fixturesUrl = '/mock', latencyMs = 350 } = {}) {
  return async (url, init) => {
    const path = new URL(url, location.origin).pathname.slice(baseUrl.length);
    await delay(latencyMs, init.signal);

    const route = ROUTES.find(([method, pattern]) => method === init.method && pattern.test(path));
    const result = route ? route[2]() : { status: 404, fixture: 'error-not-found.json' };
    const body = result.fixture
      ? await (await fetch(`${fixturesUrl}/${result.fixture}`)).text()
      : JSON.stringify(result.json);

    return new Response(body, {
      status: result.status ?? 200,
      headers: { 'Content-Type': 'application/json', 'X-Request-Id': init.headers['X-Request-Id'] },
    });
  };
}
