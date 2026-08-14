import { test as base, APIRequestContext } from "@playwright/test";
import { readFileSync } from "fs";
import path from "path";
import { TEST_CREDENTIALS } from "../config/test-credentials";
import {
  createTestMember,
  createSyncTransaction,
  createStorno,
  createSettlement,
} from "../utils/transactions";
import { minimumExecutionDate, serverToday } from "../utils/dates";
import { settlementFactory as buildSettlementFactory, SettlementFactory } from "../utils/settlements";
import { ProfilePage } from "../pages/ProfilePage";
import { MainLayoutPage } from "../pages/MainLayoutPage";
import { ProductsPage } from "../pages/ProductsPage";

const API_BASE = "http://localhost:8080/api";
// Same paths auth.setup.ts writes its storageState to (see tests/auth.setup.ts).
const FRONTEND_BASE = "http://localhost:5173";
const ADMIN_STORAGE_STATE_PATH = path.join("playwright", ".auth", "admin.json");

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
export interface TestTransactionsFixture {
  createMember(firstName?: string, lastName?: string, baseEmail?: string): Promise<any>;
  createProduct(nameDe: string, priceCents: number, nameEn?: string): Promise<any>;
  createSyncTransaction(memberId: string, amountCents?: number, notes?: string, productId?: string): Promise<string>;
  /**
   * Storno (reverse) one specific transaction for a member (#169).
   *
   * POST /admin/transactions/{transactionId}/storno takes a single field,
   * `reason` — there is no `amount_cents` any more. The amount is DERIVED as
   * the exact negation of the target transaction and can never be supplied
   * by the caller; the member is read from the target too.
   *
   * - If `relatedTransactionId` IS supplied: that transaction is stornoed.
   *   `amountCents` is IGNORED — the backend derives the amount from the
   *   target, not from anything the caller passes.
   * - If `relatedTransactionId` is omitted: a fresh purchase transaction of
   *   `Math.abs(amountCents)` (default 1000) is created for `memberId` first
   *   and stornoed — this also guarantees each call reverses a DISTINCT
   *   purchase, since a transaction can only be stornoed once (unique
   *   index on `related_transaction_id`).
   *
   * @returns The id of the created storno transaction.
   */
  createStorno(
    memberId: string,
    amountCents?: number,
    reason?: string,
    relatedTransactionId?: string
  ): Promise<string>;
  createSettlement(transactionIds: string[], executionDate?: string): Promise<string>;
}

interface AuthFixtures {
  authenticatedTerminalRequest: APIRequestContext & {
    token: string;
  };
  testTransactions: TestTransactionsFixture;
  /**
   * Per-test settlement factory (issue #98, ruling #146): creates a member,
   * a purchase and a settlement that cover them, so settlement tests never
   * have to read — or skip on — settlements another test happened to create.
   */
  settlementFactory: SettlementFactory;
  profilePage: ProfilePage;
  mainLayoutPage: MainLayoutPage;
  productsPage: ProductsPage;
}

interface AuthWorkerFixtures {
  /**
   * Worker-scoped (#338): reuses the ONE session "setup auth" (auth.setup.ts)
   * already established, instead of performing an independent password+MFA
   * login. Logging in as the shared seeded admin is a real TOTP exchange, and
   * TOTP replay protection means two of those landing in the same 30-second
   * window fail one of them — a near certainty if this logged in fresh for
   * every one of the hundreds of tests that use it, or even once per worker.
   */
  authenticatedRequest: APIRequestContext & {
    cookieString: string;
  };
}

/**
 * Wrapper that adds session cookies to all requests (for admin API).
 *
 * `cookieString` is optional: when the underlying `request` context was
 * created with `storageState` (see the `authenticatedRequest` fixture), its
 * own cookie jar already attaches the session cookie to every request, and
 * passing an empty `cookie` header here would fight it — so an empty
 * `cookieString` means "let the context's jar handle it."
 */
class AuthenticatedRequestContext {
  constructor(
    private request: APIRequestContext,
    private cookieString: string,
    private csrfToken: string = ''
  ) {}

  private cookieHeader() {
    return this.cookieString ? { cookie: this.cookieString } : {};
  }

  get = (url: string, options?: any) =>
    this.request.get(url, {
      ...options,
      headers: {
        ...options?.headers,
        ...this.cookieHeader(),
      },
    });

  post = (url: string, options?: any) =>
    this.request.post(url, {
      ...options,
      headers: {
        ...options?.headers,
        ...this.cookieHeader(),
        'X-CSRF-Token': this.csrfToken,
      },
    });

  patch = (url: string, options?: any) =>
    this.request.patch(url, {
      ...options,
      headers: {
        ...options?.headers,
        ...this.cookieHeader(),
        'X-CSRF-Token': this.csrfToken,
      },
    });

  delete = (url: string, options?: any) =>
    this.request.delete(url, {
      ...options,
      headers: {
        ...options?.headers,
        ...this.cookieHeader(),
        'X-CSRF-Token': this.csrfToken,
      },
    });

  put = (url: string, options?: any) =>
    this.request.put(url, {
      ...options,
      headers: {
        ...options?.headers,
        ...this.cookieHeader(),
        'X-CSRF-Token': this.csrfToken,
      },
    });

  head = (url: string, options?: any) =>
    this.request.head(url, {
      ...options,
      headers: {
        ...options?.headers,
        ...this.cookieHeader(),
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

export const test = base.extend<AuthFixtures, AuthWorkerFixtures>({
  authenticatedRequest: [async ({ playwright }, use) => {
    // Reuse the ONE session the "setup auth" project (auth.setup.ts) already
    // established for the whole run, instead of performing an independent
    // password+MFA login per worker (#338). Logging in as the shared seeded
    // admin is a real TOTP exchange, and replay protection correctly refuses
    // two of those landing in the same 30-second window — a near-certainty
    // if every worker, let alone every one of the hundreds of tests that use
    // this fixture, did its own. "api-tests" depends on "setup auth" (see
    // playwright.config.ts) precisely so this file always exists first.
    const stored = JSON.parse(readFileSync(ADMIN_STORAGE_STATE_PATH, 'utf-8'));

    const frontendOrigin = stored.origins?.find((o: any) => o.origin === FRONTEND_BASE);
    const csrfToken = frontendOrigin?.localStorage?.find((item: any) => item.name === 'csrf_token')?.value;
    if (!csrfToken) {
      throw new Error(
        `No csrf_token found in ${ADMIN_STORAGE_STATE_PATH} for origin ${FRONTEND_BASE}. ` +
        `Did the "setup auth" project run first? It must run before "api-tests" (see playwright.config.ts dependencies).`
      );
    }

    const freshRequest = await playwright.request.newContext({
      baseURL: API_BASE,
      storageState: ADMIN_STORAGE_STATE_PATH,
    });

    // The session cookie travels via freshRequest's own storageState-loaded
    // cookie jar; only the CSRF token needs to be attached manually.
    const authenticatedRequest = new AuthenticatedRequestContext(freshRequest, '', csrfToken) as any;
    authenticatedRequest.cookieString = '';

    await use(authenticatedRequest);
    await freshRequest.dispose();
  }, { scope: 'worker' }],

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

      async createStorno(
        memberId: string,
        amountCents = 1000,
        reason = 'Test storno',
        relatedTransactionId?: string
      ) {
        // A storno is scoped to the transaction it reverses (#169). If the
        // caller didn't name one, create a fresh purchase for this member to
        // reverse — a distinct one each call, since a transaction can only be
        // stornoed once (unique index on related_transaction_id). When the
        // caller DID name one, amountCents is irrelevant: the backend derives
        // the storno's amount as the exact negation of the target, never from
        // anything the caller sends.
        const targetTransactionId =
          relatedTransactionId ??
          (await this.createSyncTransaction(memberId, Math.abs(amountCents) || 1000, `${reason} (auto purchase)`));

        const stornoData = createStorno(reason);
        const response = await authenticatedRequest.post(
          `${API_BASE}/admin/transactions/${targetTransactionId}/storno`,
          {
            data: stornoData,
          }
        );

        if (response.status() !== 201) {
          const error = await response.json();
          throw new Error(`Failed to create storno: ${JSON.stringify(error)}`);
        }

        const result = await response.json();
        return result.transaction?.id || result.id;
      },

      async createSettlement(transactionIds: string[], executionDate?: string) {
        // Ask the backend for a valid execution date unless the test pins one:
        // it must be a TARGET2 business day, and the answer moves with the
        // calendar (issue #11).
        const execDate = executionDate ?? (await minimumExecutionDate(authenticatedRequest));
        const settlementData = createSettlement(
          transactionIds,
          execDate,
          await serverToday(authenticatedRequest),
        );
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

  settlementFactory: async ({ authenticatedRequest, authenticatedTerminalRequest }, use) => {
    await use(buildSettlementFactory(authenticatedRequest, authenticatedTerminalRequest));
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
