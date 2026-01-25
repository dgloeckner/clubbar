import { test as base, APIRequestContext } from "@playwright/test";
import { TEST_CREDENTIALS } from "../config/test-credentials";

const API_BASE = "http://localhost:8080/api";

/**
 * Authenticated Request Fixtures
 *
 * Provides two wrapper types for authenticated API requests:
 * 1. authenticatedRequest: Admin API with session cookies
 * 2. authenticatedTerminalRequest: Terminal API with bearer token
 *
 * Usage in tests:
 *   test('my test', async ({ authenticatedRequest }) => {
 *     const response = await authenticatedRequest.get('/api/admin/members');
 *     // ... assertions
 *   });
 *
 *   test('my test', async ({ authenticatedTerminalRequest }) => {
 *     const response = await authenticatedTerminalRequest.get('/api/terminal/transactions/member-id');
 *     // ... assertions
 *   });
 */
interface AuthFixtures {
  authenticatedRequest: APIRequestContext & {
    cookieString: string;
  };
  authenticatedTerminalRequest: APIRequestContext & {
    token: string;
  };
}

/**
 * Wrapper that adds session cookies to all requests (for admin API)
 */
class AuthenticatedRequestContext {
  constructor(
    private request: APIRequestContext,
    private cookieString: string
  ) {}

  get = (url: string, options?: any) =>
    this.request.get(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,
      },
    });

  post = (url: string, options?: any) =>
    this.request.post(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,
      },
    });

  patch = (url: string, options?: any) =>
    this.request.patch(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,
      },
    });

  delete = (url: string, options?: any) =>
    this.request.delete(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,
      },
    });

  put = (url: string, options?: any) =>
    this.request.put(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,
      },
    });

  head = (url: string, options?: any) =>
    this.request.head(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,
      },
    });
}

/**
 * Wrapper that adds bearer token to all requests (for terminal API)
 */
class TerminalRequestContext {
  constructor(
    private request: APIRequestContext,
    private token: string
  ) {}

  get = (url: string, options?: any) =>
    this.request.get(url, {
      ...options,
      headers: {
        ...options?.headers,
        Authorization: `Bearer ${this.token}`,
      },
    });

  post = (url: string, options?: any) =>
    this.request.post(url, {
      ...options,
      headers: {
        ...options?.headers,
        Authorization: `Bearer ${this.token}`,
      },
    });

  patch = (url: string, options?: any) =>
    this.request.patch(url, {
      ...options,
      headers: {
        ...options?.headers,
        Authorization: `Bearer ${this.token}`,
      },
    });

  delete = (url: string, options?: any) =>
    this.request.delete(url, {
      ...options,
      headers: {
        ...options?.headers,
        Authorization: `Bearer ${this.token}`,
      },
    });

  put = (url: string, options?: any) =>
    this.request.put(url, {
      ...options,
      headers: {
        ...options?.headers,
        Authorization: `Bearer ${this.token}`,
      },
    });

  head = (url: string, options?: any) =>
    this.request.head(url, {
      ...options,
      headers: {
        ...options?.headers,
        Authorization: `Bearer ${this.token}`,
      },
    });
}

export const test = base.extend<AuthFixtures>({
  authenticatedRequest: async ({ request }, use) => {
    // Login and get session cookie
    const loginResponse = await request.post(`${API_BASE}/auth/login`, {
      data: {
        email: TEST_CREDENTIALS.admin.email,
        password: TEST_CREDENTIALS.admin.password,
      },
    });

    // Extract session cookie from Set-Cookie header
    const setCookieHeader = loginResponse.headers()["set-cookie"];
    let fullCookieString = Array.isArray(setCookieHeader)
      ? setCookieHeader[0]
      : setCookieHeader || "";

    // Extract just the name=value part (remove expires, path, httponly, etc.)
    const cookieString = fullCookieString.split(";")[0];

    // Create authenticated request wrapper
    const authenticatedRequest = new AuthenticatedRequestContext(
      request,
      cookieString
    ) as any;
    authenticatedRequest.cookieString = cookieString;

    // Provide the authenticated request to the test
    await use(authenticatedRequest);
  },

  authenticatedTerminalRequest: async ({ request }, use) => {
    // Create terminal request wrapper with bearer token
    const terminalRequest = new TerminalRequestContext(
      request,
      TEST_CREDENTIALS.terminal.token
    ) as any;
    terminalRequest.token = TEST_CREDENTIALS.terminal.token;

    // Provide the authenticated terminal request to the test
    await use(terminalRequest);
  },
});

export { expect } from "@playwright/test";
