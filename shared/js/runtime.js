import { createApiClient, fetchTransport } from './api-client.js';
import { createMockTransport } from './mock-transport.js';

const MOCK_KEY = 'storefront.mock';

/**
 * Mock mode is switched with ?mock=1 / ?mock=0 and remembered for the tab.
 */
export function isMockMode() {
  const param = new URLSearchParams(location.search).get('mock');

  try {
    if (param !== null) {
      if (param === '0') sessionStorage.removeItem(MOCK_KEY);
      else sessionStorage.setItem(MOCK_KEY, '1');
    }
    return sessionStorage.getItem(MOCK_KEY) === '1';
  } catch {
    // Storage can be unavailable (private mode, blocked site data).
    return param !== null && param !== '0';
  }
}

export function createRuntime() {
  const mock = isMockMode();
  return {
    mock,
    api: createApiClient({ transport: mock ? createMockTransport() : fetchTransport }),
  };
}
