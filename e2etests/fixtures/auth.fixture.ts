import { test as base, APIRequestContext } from "@playwright/test";
import { TEST_CREDENTIALS } from "../config/test-credentials";
import {
  createTestMember,
  createSyncTransaction,
  createCorrection,
  createSettlement,
} from "../utils/transactions";
import { ProfilePage } from "../pages/ProfilePage";
import { MainLayoutPage } from "../pages/MainLayoutPage";
import { ProductsPage } from "../pages/ProductsPage";

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
/**
 * Test transaction helper interface
 * Provides convenient API methods for creating test data
 */
interface TestTransactionsFixture {
  createMember(firstName?: string, lastName?: string, baseEmail?: string): Promise<any>;
  createProduct(nameDe: string, priceCents: number, nameEn?: string): Promise<any>;
  createSyncTransaction(memberId: string, amountCents?: number, notes?: string, productId?: string): Promise<string>;
  createCorrection(
    memberId: string,
    amountCents?: number,
    notes?: string,
    reason?: 'adjustment' | 'refund' | 'discount'
  ): Promise<string>;
  createSettlement(transactionIds: string[], daysFromNow?: number): Promise<string>;
}

interface AuthFixtures {
  authenticatedRequest: APIRequestContext & {
    cookieString: string;
  };
  authenticatedTerminalRequest: APIRequestContext & {
    token: string;
  };
  testTransactions: TestTransactionsFixture;
  profilePage: ProfilePage;
  mainLayoutPage: MainLayoutPage;
  productsPage: ProductsPage;
}

/**
 * Wrapper that adds session cookies to all requests (for admin API)
 */
class AuthenticatedRequestContext {
  constructor(
    private request: APIRequestContext,
    private cookieString: string,
    private csrfToken: string = ''
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
        'X-CSRF-Token': this.csrfToken,
      },
    });

  patch = (url: string, options?: any) =>
    this.request.patch(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,
        'X-CSRF-Token': this.csrfToken,
      },
    });

  delete = (url: string, options?: any) =>
    this.request.delete(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,
        'X-CSRF-Token': this.csrfToken,
      },
    });

  put = (url: string, options?: any) =>
    this.request.put(url, {
      ...options,
      headers: {
        ...options?.headers,
        cookie: this.cookieString,
        'X-CSRF-Token': this.csrfToken,
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
  authenticatedRequest: async ({ playwright }, use) => {
    // Create a fresh request context without storageState to avoid
    // sending existing session cookies that prevent Set-Cookie from being returned
    const freshRequest = await playwright.request.newContext({
      baseURL: API_BASE,
      storageState: { cookies: [], origins: [] },
    });

    // Login and get session cookie
    const loginResponse = await freshRequest.post(`${API_BASE}/auth/login`, {
      data: {
        email: TEST_CREDENTIALS.admin.email,
        password: TEST_CREDENTIALS.admin.password,
      },
    });

    // Verify login succeeded
    if (!loginResponse.ok()) {
      const errorBody = await loginResponse.text();
      throw new Error(`Admin login failed with status ${loginResponse.status()}: ${errorBody}`);
    }

    // Extract session cookie from Set-Cookie header
    const setCookieHeader = loginResponse.headers()["set-cookie"];
    let fullCookieString = Array.isArray(setCookieHeader)
      ? setCookieHeader[0]
      : setCookieHeader || "";

    // Extract just the name=value part (remove expires, path, httponly, etc.)
    const cookieString = fullCookieString.split(";")[0];

    // Extract CSRF token from login response
    const loginData = await loginResponse.json();
    const csrfToken = loginData.csrf_token || '';

    if (!cookieString) {
      // Fallback: try headersArray() which preserves duplicate headers
      const headersArray = loginResponse.headersArray();
      const setCookieFromArray = headersArray.find(h => h.name.toLowerCase() === 'set-cookie');
      if (setCookieFromArray) {
        const fallbackCookie = setCookieFromArray.value.split(';')[0];
        if (fallbackCookie) {
          const authenticatedRequest = new AuthenticatedRequestContext(
            freshRequest,
            fallbackCookie,
            csrfToken
          ) as any;
          authenticatedRequest.cookieString = fallbackCookie;
          await use(authenticatedRequest);
          await freshRequest.dispose();
          return;
        }
      }
      throw new Error('No session cookie received from login response');
    }

    // Create authenticated request wrapper
    const authenticatedRequest = new AuthenticatedRequestContext(
      freshRequest,
      cookieString,
      csrfToken
    ) as any;
    authenticatedRequest.cookieString = cookieString;

    // Provide the authenticated request to the test
    await use(authenticatedRequest);

    // Cleanup
    await freshRequest.dispose();
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

  testTransactions: async ({ authenticatedRequest, authenticatedTerminalRequest }, use) => {
    // Create test transactions fixture with convenient API methods
    const fixture: TestTransactionsFixture = {
      async createMember(firstName = 'TestMember', lastName = 'Test', baseEmail = 'member') {
        const memberData = createTestMember(firstName, lastName, baseEmail);
        const response = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
          data: memberData,
        });

        if (response.status() !== 201) {
          const error = await response.json();
          throw new Error(`Failed to create member: ${JSON.stringify(error)}`);
        }

        return await response.json();
      },

      async createProduct(nameDe: string, priceCents: number, nameEn?: string) {
        // Create a category first (products require one)
        const timestamp = Date.now();
        const catResponse = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
          data: {
            names: { de: `Kat_${timestamp}`, en: `Cat_${timestamp}` },
          },
        });

        if (catResponse.status() !== 201) {
          const error = await catResponse.json();
          throw new Error(`Failed to create category: ${JSON.stringify(error)}`);
        }

        const category = await catResponse.json();

        // Create the product
        const names: Record<string, string> = { de: nameDe };
        if (nameEn) names.en = nameEn;

        const prodResponse = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
          data: {
            names,
            price_cents: priceCents,
            category_id: category.id,
          },
        });

        if (prodResponse.status() !== 201) {
          const error = await prodResponse.json();
          throw new Error(`Failed to create product: ${JSON.stringify(error)}`);
        }

        return await prodResponse.json();
      },

      async createSyncTransaction(memberId: string, amountCents = 2500, notes = 'Test transaction', productId?: string) {
        const txnData = createSyncTransaction(memberId, amountCents, notes, productId);
        const response = await authenticatedTerminalRequest.post(`${API_BASE}/sync/transactions`, {
          data: {
            transactions: [txnData],
          },
        });

        if (response.status() !== 201) {
          const error = await response.json();
          throw new Error(`Failed to create sync transaction: ${JSON.stringify(error)}`);
        }

        const result = await response.json();
        return result.accepted_ids?.[0] || txnData.id;
      },

      async createCorrection(
        memberId: string,
        amountCents = 1000,
        notes = 'Test correction',
        reason = 'adjustment'
      ) {
        const correctionData = createCorrection(amountCents, notes, reason);
        const response = await authenticatedRequest.post(
          `${API_BASE}/admin/members/${memberId}/transactions/correction`,
          {
            data: correctionData,
          }
        );

        if (response.status() !== 201) {
          const error = await response.json();
          throw new Error(`Failed to create correction: ${JSON.stringify(error)}`);
        }

        const result = await response.json();
        return result.transaction?.id || result.id;
      },

      async createSettlement(transactionIds: string[], daysFromNow = 7) {
        const settlementData = createSettlement(transactionIds, daysFromNow);
        const response = await authenticatedRequest.post(`${API_BASE}/admin/settlements`, {
          data: settlementData,
        });

        if (response.status() !== 201) {
          const error = await response.json();
          throw new Error(`Failed to create settlement: ${JSON.stringify(error)}`);
        }

        const result = await response.json();
        return result.id;
      },
    };

    await use(fixture);
  },

  profilePage: async ({ page }, use) => {
    await use(new ProfilePage(page));
  },

  mainLayoutPage: async ({ page }, use) => {
    await use(new MainLayoutPage(page));
  },

  productsPage: async ({ page }, use) => {
    await use(new ProductsPage(page));
  },
});

export { expect } from "@playwright/test";
