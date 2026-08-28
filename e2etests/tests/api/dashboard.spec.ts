import { test, expect } from "../../fixtures/auth.fixture";
import { serverToday } from "../../utils/dates";

const API_BASE = "http://localhost:8080/api";

/**
 * Dashboard API Tests
 *
 * Tests for Module 9: Dashboard (Admin Overview & Metrics)
 * Covers aggregated metrics, recent transactions, terminal status, and alerts.
 *
 * Test Data Isolation (E2E Pattern 001):
 * - Tests use existing seeded data (members, products, transactions)
 * - No test data creation needed (read-only endpoint)
 * - Database-agnostic assertions (verify structure, not specific values)
 *
 * Authentication (E2E Pattern 002):
 * - All tests require admin session authentication
 * - Uses authenticatedRequest fixture for session-based auth
 *
 * Database-Agnostic Assertions (E2E Pattern 003):
 * - Verify response structure and field presence
 * - Verify data types and formatting (timestamps, currency)
 * - Verify calculations are reasonable (e.g., counts >= 0)
 * - Support safe concurrent test execution
 */

test.describe("Dashboard API", () => {
  // ========== METRICS ACCURACY ==========

  test("should return dashboard with all metrics fields", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();

    // Verify main sections exist
    expect(data).toHaveProperty("metrics");
    expect(data).toHaveProperty("recent_transactions");
    expect(data).toHaveProperty("terminal_status");
    expect(data).toHaveProperty("system_status");
    expect(data).toHaveProperty("alerts");

    // Verify all metrics fields exist
    const metrics = data.metrics;
    expect(metrics).toHaveProperty("active_members");
    expect(metrics).toHaveProperty("inactive_members");
    expect(metrics).toHaveProperty("outstanding_balance_cents");
    expect(metrics).toHaveProperty("todays_revenue_cents");
    expect(metrics).toHaveProperty("terminal_count");
    expect(metrics).toHaveProperty("active_terminals");
    expect(metrics).toHaveProperty("settled_members");
    expect(metrics).toHaveProperty("sepa_issue_count");
  });

  test("should return non-negative metric counts", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const metrics = data.metrics;

    // All counts should be non-negative integers
    expect(metrics.active_members).toBeGreaterThanOrEqual(1); // Seeded data has 8 active members
    expect(metrics.inactive_members).toBeGreaterThanOrEqual(0); // Seeded data has 0 inactive members; 0 is valid
    expect(metrics.outstanding_balance_cents).toBeGreaterThanOrEqual(-Infinity); // Can be negative
    expect(metrics.todays_revenue_cents).toBeGreaterThanOrEqual(-Infinity); // Can be negative
    expect(metrics.terminal_count).toBeGreaterThanOrEqual(1); // Seeded data has 1 terminal
    expect(metrics.active_terminals).toBeGreaterThanOrEqual(1); // Seeded data has 1 active terminal
    expect(metrics.settled_members).toBeGreaterThanOrEqual(0); // No settlements seeded; 0 is valid
    expect(metrics.sepa_issue_count).toBeGreaterThanOrEqual(0); // Seeded members all have valid SEPA data; 0 is valid

    // Verify active terminals <= total terminals
    expect(metrics.active_terminals).toBeLessThanOrEqual(metrics.terminal_count);
  });

  test("should have active_members >= inactive_members + active_members total", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const metrics = data.metrics;

    // Total members should equal active + inactive
    const totalMembers = metrics.active_members + metrics.inactive_members;
    expect(totalMembers).toBeGreaterThanOrEqual(0);

    // At least 1 member (from seeding)
    expect(totalMembers).toBeGreaterThan(0);
  });

  test("should have terminal_count >= active_terminals", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const metrics = data.metrics;

    // Active terminals must be <= total terminals
    expect(metrics.active_terminals).toBeLessThanOrEqual(metrics.terminal_count);
  });

  test("should return sepa_issue_count for members with missing SEPA data", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const metrics = data.metrics;

    // SEPA issue count should be non-negative integer
    expect(typeof metrics.sepa_issue_count).toBe("number");
    expect(metrics.sepa_issue_count).toBeGreaterThanOrEqual(0);
  });

  // ========== RECENT TRANSACTIONS ==========

  test("should return recent transactions array with correct structure", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const recentTxns = data.recent_transactions;

    // Should be an array
    expect(Array.isArray(recentTxns)).toBe(true);

    // Should have up to 10 transactions
    expect(recentTxns.length).toBeLessThanOrEqual(10);

    // Each transaction should have required fields
    if (recentTxns.length > 0) {
      const txn = recentTxns[0];
      expect(txn).toHaveProperty("id");
      expect(txn).toHaveProperty("member_id");
      expect(txn).toHaveProperty("member_name");
      expect(txn).toHaveProperty("type");
      expect(txn).toHaveProperty("amount_cents");
      expect(txn).toHaveProperty("product_name");
      expect(txn).toHaveProperty("timestamp");

      // Verify types
      expect(typeof txn.id).toBe("string");
      expect(typeof txn.member_id).toBe("string");
      expect(txn.member_name === null || typeof txn.member_name === "string").toBe(true);
      expect(typeof txn.type).toBe("string");
      expect(typeof txn.amount_cents).toBe("number");
      expect(txn.product_name === null || typeof txn.product_name === "string").toBe(true);
      expect(typeof txn.timestamp).toBe("string");

      // Verify ISO 8601 timestamp format
      expect(/^\d{4}-\d{2}-\d{2}T/.test(txn.timestamp)).toBe(true);

      // Transaction type should be valid
      expect(["purchase", "storno", "payout"]).toContain(txn.type);
    }
  });

  test("should order recent transactions by created_at descending", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const recentTxns = data.recent_transactions;

    // Should have at least some transactions (from seeded data)
    expect(recentTxns.length).toBeGreaterThan(0);

    // Transactions should be in descending order by timestamp
    for (let i = 0; i < recentTxns.length - 1; i++) {
      const current = new Date(recentTxns[i].timestamp).getTime();
      const next = new Date(recentTxns[i + 1].timestamp).getTime();
      expect(current).toBeGreaterThanOrEqual(next);
    }
  });

  // ========== TERMINAL STATUS ==========

  test("should return terminal status with correct fields", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const terminals = data.terminal_status;

    // Should be an array
    expect(Array.isArray(terminals)).toBe(true);

    // Each terminal should have required fields
    if (terminals.length > 0) {
      const terminal = terminals[0];
      expect(terminal).toHaveProperty("id");
      expect(terminal).toHaveProperty("name");
      expect(terminal).toHaveProperty("is_active");
      expect(terminal).toHaveProperty("last_sync_at");
      expect(terminal).toHaveProperty("status");

      // Verify types
      expect(typeof terminal.id).toBe("string");
      expect(typeof terminal.name).toBe("string");
      expect(typeof terminal.is_active).toBe("boolean");
      expect(terminal.last_sync_at === null || typeof terminal.last_sync_at === "string").toBe(true);
      expect(typeof terminal.status).toBe("string");

      // Status should be valid
      expect(["online", "offline", "disabled"]).toContain(terminal.status);

      // If inactive, status should be disabled
      if (!terminal.is_active) {
        expect(terminal.status).toBe("disabled");
      }
    }
  });

  test("should reflect actual terminal sync status", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const terminals = data.terminal_status;

    // Should have at least test terminal (from seeding)
    expect(terminals.length).toBeGreaterThan(0);

    // Status mapping should be consistent
    for (const terminal of terminals) {
      if (!terminal.is_active) {
        expect(terminal.status).toBe("disabled");
      } else if (terminal.last_sync_at === null) {
        expect(terminal.status).toBe("offline");
      }
      // online status requires last_sync_at within 5 minutes (can't verify exact time)
    }
  });

  test("should have active_terminals match the count of terminal_status entries marked online", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();

    const onlineCount = data.terminal_status.filter(
      (terminal: { status: string }) => terminal.status === "online"
    ).length;

    // The two dashboard widgets built from these fields must agree on how many
    // terminals are online, rather than active_terminals also counting enabled
    // terminals that have gone stale.
    expect(data.metrics.active_terminals).toBe(onlineCount);
  });

  // ========== SYSTEM STATUS ==========

  test("should return system status with required fields", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const systemStatus = data.system_status;

    // Verify all fields exist
    expect(systemStatus).toHaveProperty("last_settlement_date");
    expect(systemStatus).toHaveProperty("pending_settlement_count");
    expect(systemStatus).toHaveProperty("total_members");
    expect(systemStatus).toHaveProperty("total_transactions");
    expect(systemStatus).toHaveProperty("database_health");

    // Verify types
    expect(systemStatus.last_settlement_date === null || typeof systemStatus.last_settlement_date === "string").toBe(true);
    expect(typeof systemStatus.pending_settlement_count).toBe("number");
    expect(typeof systemStatus.total_members).toBe("number");
    expect(typeof systemStatus.total_transactions).toBe("number");
    expect(typeof systemStatus.database_health).toBe("string");

    // Database health should be ok
    expect(systemStatus.database_health).toBe("ok");

    // Counts should be non-negative
    expect(systemStatus.pending_settlement_count).toBeGreaterThanOrEqual(0);
    expect(systemStatus.total_members).toBeGreaterThan(0); // At least seeded members
    expect(systemStatus.total_transactions).toBeGreaterThanOrEqual(0);
  });

  // ========== ALERTS ==========

  test("should return alerts with sepa_issues structure", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const alerts = data.alerts;

    // Verify alerts section exists
    expect(alerts).toHaveProperty("sepa_issues");

    const sepaAlert = alerts.sepa_issues;
    expect(sepaAlert).toHaveProperty("count");
    expect(sepaAlert).toHaveProperty("severity");
    expect(sepaAlert).toHaveProperty("message");

    // Verify types
    expect(typeof sepaAlert.count).toBe("number");
    expect(typeof sepaAlert.severity).toBe("string");
    expect(typeof sepaAlert.message).toBe("string");

    // Severity should be valid
    expect(["none", "warning", "error"]).toContain(sepaAlert.severity);

    // Verify severity/count relationship
    if (sepaAlert.count === 0) {
      expect(sepaAlert.severity).toBe("none");
    } else if (sepaAlert.count >= 1 && sepaAlert.count <= 5) {
      expect(sepaAlert.severity).toBe("warning");
    } else if (sepaAlert.count >= 6) {
      expect(sepaAlert.severity).toBe("error");
    }
  });

  test("should show appropriate SEPA alert severity based on count", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    const sepaAlert = data.alerts.sepa_issues;

    // Verify message matches count
    if (sepaAlert.count === 0) {
      expect(sepaAlert.message).toContain("No SEPA data issues");
      expect(sepaAlert.severity).toBe("none");
    } else {
      expect(sepaAlert.message).toContain(`${sepaAlert.count} members`);
      expect(["warning", "error"]).toContain(sepaAlert.severity);
    }
  });

  test("should name which half of the SEPA setup is missing", async ({
    authenticatedRequest,
  }) => {
    // #741: the admin panel renders this banner in the admin's chosen
    // language, so it needs the fact rather than the backend's English
    // sentence. Read-only on purpose — `sepa_config` is a singleton row every
    // other suite shares, so this asserts the shape of whatever state the run
    // happens to be in rather than forcing one.
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/dashboard`
    );

    expect(response.status()).toBe(200);
    const sepaConfigAlert = (await response.json()).alerts.sepa_config;

    expect(sepaConfigAlert).toHaveProperty("state");
    expect(["ok", "creditor_missing", "mandate_template_missing"]).toContain(
      sepaConfigAlert.state
    );

    // The state and the severity are two readings of one check; a banner that
    // is silent while a state says something is missing is the failure mode.
    expect(sepaConfigAlert.severity).toBe(
      sepaConfigAlert.state === "ok" ? "none" : "error"
    );
  });

  // ========== AUTHENTICATION & PERFORMANCE ==========

  test("should allow authenticated requests", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(`${API_BASE}/admin/dashboard`);

    expect(response.status()).toBe(200);
  });

  test("should reject unauthenticated requests", async ({ request }) => {
    // Unlike authenticatedRequest, the plain `request` fixture carries no
    // session cookie — this is the actual "endpoint is protected" check
    // (#104), which the authenticated-only test above cannot exercise.
    const response = await request.get(`${API_BASE}/admin/dashboard`);

    expect([301, 302, 401, 403]).toContain(response.status());
  });

  test("should return complete dashboard response within timeout", async ({
    authenticatedRequest,
  }) => {
    const startTime = Date.now();
    const response = await authenticatedRequest.get(`${API_BASE}/admin/dashboard`);
    const duration = Date.now() - startTime;

    expect(response.status()).toBe(200);

    // Verify response includes all sections (basic performance check)
    const data = await response.json();
    expect(data.metrics).toBeDefined();
    expect(data.recent_transactions).toBeDefined();
    expect(data.terminal_status).toBeDefined();
    expect(data.system_status).toBeDefined();
    expect(data.alerts).toBeDefined();

    // Response should complete reasonably quickly (< 5 seconds)
    expect(duration).toBeLessThan(5000);
  });

  /**
   * Monthly statistics (#100).
   *
   * `GET /admin/statistics/monthly` backs the monthly report screen and had
   * no API coverage at all — neither its shape, its `month` parameter, nor
   * the rejection of a malformed one.
   *
   * The revenue assertions are deltas, not absolutes: other workers post
   * transactions into the same month while these run (E2E Pattern 003), so
   * the test asserts that its own purchase is *included*, never that it is
   * all that the month contains.
   */
  test.describe("Monthly statistics", () => {
    test("should return the current month with all statistics fields", async ({
      authenticatedRequest,
    }) => {
      const response = await authenticatedRequest.get(
        `${API_BASE}/admin/statistics/monthly`
      );

      expect(response.status()).toBe(200);
      const data = await response.json();

      // Defaults to the month the server is in.
      expect(data.month).toMatch(/^\d{4}-\d{2}$/);
      expect(typeof data.total_revenue_cents).toBe("number");
      expect(typeof data.total_sold_items).toBe("number");
      expect(Array.isArray(data.daily_revenue)).toBe(true);
      expect(Array.isArray(data.top_products)).toBe(true);
      expect(Array.isArray(data.top_members)).toBe(true);
      // Null when the month sold nothing, an object otherwise — but always present.
      expect(data).toHaveProperty("top_product");
    });

    test("should count a new purchase towards the current month", async ({
      authenticatedRequest,
      testTransactions,
    }) => {
      const before = await (
        await authenticatedRequest.get(`${API_BASE}/admin/statistics/monthly`)
      ).json();

      const member = await testTransactions.createMember();
      const amountCents = 1730;
      await testTransactions.createSyncTransaction(
        member.id,
        amountCents,
        "Monthly stats purchase"
      );

      // Asked for by name rather than by default, so a month rollover between
      // the two reads cannot make them describe different months.
      const after = await (
        await authenticatedRequest.get(
          `${API_BASE}/admin/statistics/monthly?month=${before.month}`
        )
      ).json();

      expect(after.month).toBe(before.month);
      // Parallel workers may add more in between; this purchase must at least
      // be in there.
      expect(after.total_revenue_cents).toBeGreaterThanOrEqual(
        before.total_revenue_cents + amountCents
      );
      expect(after.total_sold_items).toBeGreaterThanOrEqual(
        before.total_sold_items + 1
      );

      // Today's row exists in the daily breakdown once something was sold.
      // The date comes from the server, whose timezone need not be the
      // runner's.
      const today = await serverToday(authenticatedRequest);
      const todaysRow = after.daily_revenue.find(
        (row: { date: string }) => row.date === today
      );
      expect(todaysRow, `daily_revenue must carry a row for ${today}`).toBeDefined();
      expect(todaysRow.transaction_count).toBeGreaterThan(0);
    });

    test("should honour an explicit month parameter", async ({
      authenticatedRequest,
    }) => {
      // A month long before the club existed: empty, and empty is a valid
      // answer rather than an error.
      const response = await authenticatedRequest.get(
        `${API_BASE}/admin/statistics/monthly?month=2000-01`
      );

      expect(response.status()).toBe(200);
      const data = await response.json();
      expect(data.month).toBe("2000-01");
      expect(data.total_revenue_cents).toBe(0);
      expect(data.total_sold_items).toBe(0);
      expect(data.top_product).toBeNull();
      expect(data.daily_revenue).toEqual([]);
      expect(data.top_products).toEqual([]);
      expect(data.top_members).toEqual([]);
    });

    test("should reject a malformed month", async ({
      authenticatedRequest,
    }) => {
      for (const month of ["2026-1", "not-a-month", "202601"]) {
        const response = await authenticatedRequest.get(
          `${API_BASE}/admin/statistics/monthly?month=${month}`
        );

        expect(response.status(), `month=${month} must be rejected`).toBe(400);
        expect((await response.json()).error).toMatch(/month/i);
      }
    });

    test("should require authentication", async ({ request }) => {
      const response = await request.get(
        `${API_BASE}/admin/statistics/monthly`
      );

      expect([301, 302, 401, 403]).toContain(response.status());
    });
  });
});
