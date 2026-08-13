import { test, expect } from "../../fixtures/auth.fixture";

const API_BASE = "http://localhost:8080/api";

/**
 * Terminal Activity API Tests
 *
 * Tests for GET /api/admin/reports/terminal-activity endpoint.
 * Returns terminal session data, hourly distribution, and terminal list.
 *
 * Query params: date_from (required), date_to (required), terminal_id (optional)
 *
 * Test Data Isolation (E2E Pattern 001):
 * - Tests use existing seeded data (read-only endpoint)
 * - Database-agnostic assertions (verify structure, not specific values)
 *
 * Authentication (E2E Pattern 002):
 * - All tests require admin session authentication
 */

test.describe("Terminal Activity API", () => {
  // ========== BASIC RESPONSE ==========

  test("returns terminal activity with all three sections", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/terminal-activity?date_from=2020-01-01&date_to=2030-12-31`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    expect(body).toHaveProperty("sessions");
    expect(body).toHaveProperty("hourly_distribution");
    expect(body).toHaveProperty("terminals");

    expect(Array.isArray(body.sessions)).toBe(true);
    expect(Array.isArray(body.hourly_distribution)).toBe(true);
    expect(Array.isArray(body.terminals)).toBe(true);
  });

  // ========== HOURLY DISTRIBUTION ==========

  test("hourly distribution has 24 entries (hours 0-23)", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/terminal-activity?date_from=2020-01-01&date_to=2030-12-31`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    expect(body.hourly_distribution.length).toBe(24);

    // Verify hours 0 through 23 are present
    const hours = body.hourly_distribution.map((h: any) => h.hour);
    for (let i = 0; i < 24; i++) {
      expect(hours).toContain(i);
    }

    // Each entry should have hour and transaction_count
    for (const entry of body.hourly_distribution) {
      expect(entry).toHaveProperty("hour");
      expect(entry).toHaveProperty("transaction_count");
      expect(typeof entry.hour).toBe("number");
      expect(typeof entry.transaction_count).toBe("number");
      expect(entry.hour).toBeGreaterThanOrEqual(0);
      expect(entry.hour).toBeLessThanOrEqual(23);
      expect(entry.transaction_count).toBeGreaterThanOrEqual(0);
    }
  });

  // ========== SESSIONS FIELDS ==========

  test("sessions have correct fields", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/terminal-activity?date_from=2020-01-01&date_to=2030-12-31`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    if (body.sessions.length > 0) {
      const session = body.sessions[0];
      expect(session).toHaveProperty("date");
      expect(session).toHaveProperty("start_time");
      expect(session).toHaveProperty("end_time");
      expect(session).toHaveProperty("transaction_count");
      expect(session).toHaveProperty("revenue_cents");

      expect(typeof session.date).toBe("string");
      expect(typeof session.start_time).toBe("string");
      expect(typeof session.end_time).toBe("string");
      expect(typeof session.transaction_count).toBe("number");
      expect(typeof session.revenue_cents).toBe("number");
    }
  });

  // ========== TERMINALS FIELDS ==========

  test("terminals have correct fields", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/terminal-activity?date_from=2020-01-01&date_to=2030-12-31`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    if (body.terminals.length > 0) {
      const terminal = body.terminals[0];
      expect(terminal).toHaveProperty("id");
      expect(terminal).toHaveProperty("name");
      expect(terminal).toHaveProperty("transaction_count");

      expect(typeof terminal.id).toBe("string");
      expect(typeof terminal.name).toBe("string");
      expect(typeof terminal.transaction_count).toBe("number");
    }
  });

  // ========== REQUIRED PARAMETER VALIDATION ==========

  test("missing date_from and date_to returns 400", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/terminal-activity`
    );

    expect([400, 422]).toContain(response.status());
  });

  test("only date_from without date_to returns 400", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/terminal-activity?date_from=2024-01-01`
    );

    expect([400, 422]).toContain(response.status());
  });
});
