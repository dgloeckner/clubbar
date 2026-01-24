import { test as base, APIRequestContext } from "@playwright/test";

const API_BASE = "http://localhost:8080/api";
const ADMIN_EMAIL = "admin@example.com";
const ADMIN_PASSWORD = "password123";

/**
 * Authenticated Request Fixture
 *
 * Provides a wrapper around APIRequestContext that automatically adds
 * admin session cookies to all requests.
 *
 * Usage in tests:
 *   test('my test', async ({ authenticatedRequest }) => {
 *     const response = await authenticatedRequest.get('/api/admin/members');
 *     // ... assertions
 *   });
 */
interface AuthFixtures {
  authenticatedRequest: APIRequestContext & {
    cookieString: string;
  };
}

/**
 * Wrapper that adds cookies to all requests
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

export const test = base.extend<AuthFixtures>({
  authenticatedRequest: async ({ request }, use) => {
    // Login and get session cookie
    const loginResponse = await request.post(`${API_BASE}/auth/login`, {
      data: {
        email: ADMIN_EMAIL,
        password: ADMIN_PASSWORD,
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
});

export { expect } from "@playwright/test";
