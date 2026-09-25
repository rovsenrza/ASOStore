/**
 * Single fetch-based API client shared by the portal and the admin panel
 * (FULL_PLAN §10 "fetch() API client in a single module", IMPLEMENTATION_PLAN D13).
 *
 * Every response uses the {data, meta, error} envelope. Failures are thrown as
 * ApiError with a stable `code`; UI code maps the code to copy through i18n.
 * Client-only codes: OFFLINE, NETWORK_ERROR.
 */

export class ApiError extends Error {
  constructor({ code, message = '', details = {}, status = 0, requestId = null }) {
    super(message || code);
    this.name = 'ApiError';
    this.code = code;
    this.details = details;
    this.status = status;
    this.requestId = requestId;
  }
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

function newRequestId() {
  const random = globalThis.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}${Math.random().toString(36).slice(2)}`;
  return `web-${random}`;
}

function readCookie(name) {
  const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

export const fetchTransport = (url, init) => fetch(url, init);

/**
 * Laravel Sanctum issues the XSRF-TOKEN cookie from this endpoint (IMPLEMENTATION_PLAN D2).
 */
async function fetchCsrfCookie() {
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
}

export function createApiClient({ baseUrl = '/api/v1', transport = fetchTransport } = {}) {
  const usesCookies = transport === fetchTransport;

  async function request(method, path, options = {}) {
    try {
      return await send(method, path, options);
    } catch (error) {
      // 419: the session's CSRF token changed (e.g. after sign-in). Refresh it and retry once.
      if (usesCookies && error instanceof ApiError && error.status === 419 && !options.retried) {
        await fetchCsrfCookie();
        return send(method, path, { ...options, retried: true });
      }
      throw error;
    }
  }

  async function send(method, path, { body, idempotencyKey, signal, headers: extra = {} } = {}) {
    // extra: request-specific headers, e.g. X-Chunk-SHA256 for upload chunks.
    const headers = { ...extra, Accept: 'application/json', 'X-Request-Id': newRequestId() };

    // JSON by default; FormData (file uploads) and Blob (raw chunks) are sent as they are.
    let payload;
    if (body instanceof FormData) {
      payload = body;
    } else if (body instanceof Blob) {
      payload = body;
      headers['Content-Type'] = 'application/octet-stream';
    } else if (body !== undefined) {
      payload = JSON.stringify(body);
      headers['Content-Type'] = 'application/json';
    }
    if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
    if (!SAFE_METHODS.has(method) && usesCookies) {
      if (!readCookie('XSRF-TOKEN')) await fetchCsrfCookie();
      const xsrf = readCookie('XSRF-TOKEN');
      if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
    }

    let response;
    try {
      response = await transport(baseUrl + path, {
        method,
        headers,
        body: payload,
        credentials: 'same-origin',
        signal,
      });
    } catch (error) {
      if (error?.name === 'AbortError') throw error;
      throw new ApiError({
        code: navigator.onLine === false ? 'OFFLINE' : 'NETWORK_ERROR',
        requestId: headers['X-Request-Id'],
      });
    }

    const requestId = response.headers.get('X-Request-Id') ?? headers['X-Request-Id'];
    let envelope;
    try {
      envelope = await response.json();
    } catch {
      throw new ApiError({ code: 'INTERNAL', status: response.status, requestId });
    }

    if (!response.ok || envelope?.error) {
      const error = envelope?.error ?? {};
      throw new ApiError({
        code: error.code ?? 'INTERNAL',
        message: error.message,
        details: error.details ?? {},
        status: response.status,
        requestId: envelope?.meta?.request_id ?? requestId,
      });
    }

    return { data: envelope.data, meta: envelope.meta ?? {}, status: response.status };
  }

  return {
    request,
    get: (path, options) => request('GET', path, options),
    post: (path, body, options = {}) => request('POST', path, { ...options, body }),
    patch: (path, body, options = {}) => request('PATCH', path, { ...options, body }),
    put: (path, body, options = {}) => request('PUT', path, { ...options, body }),
    delete: (path, options) => request('DELETE', path, options),
  };
}
