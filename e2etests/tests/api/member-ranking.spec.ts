import { test, expect } from "../../fixtures/auth.fixture";

const API_BASE = "http://localhost:8080/api";

/**
 * Member Ranking API Tests
 *
 * Tests for GET /api/admin/reports/member-ranking endpoint.
 * Returns members ranked by total consumption amount.
 *
 * Query params: date_from, date_to, anonymize (boolean), limit (10|25|50|100)
 *
 * Test Data Isolation (E2E Pattern 001):
 * - Tests use existing seeded data (read-only endpoint)
 * - Database-agnostic assertions (verify structure, not specific values)
 *
 * Authentication (E2E Pattern 002):
 * - All tests require admin session authentication
 */

test.describe("Member Ranking API", () => {
  // ========== BASIC RESPONSE ==========

  test("returns ranking with data array", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    expect(body).toHaveProperty("data");
    expect(Array.isArray(body.data)).toBe(true);
  });

  // ========== FIELD VALIDATION ==========

  test("ranked members have required fields", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    if (body.data.length > 0) {
      const member = body.data[0];
      expect(member).toHaveProperty("rank");
      expect(member).toHaveProperty("member_name");
      expect(member).toHaveProperty("total_amount_cents");
      expect(member).toHaveProperty("transaction_count");

      // Verify types
      expect(typeof member.rank).toBe("number");
      expect(typeof member.member_name).toBe("string");
      expect(typeof member.total_amount_cents).toBe("number");
      expect(typeof member.transaction_count).toBe("number");

      // Rank should start at 1
      expect(member.rank).toBe(1);
    }
  });

  // ========== ORDERING ==========

  test("results ordered by total_amount_cents descending", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    if (body.data.length > 1) {
      for (let i = 0; i < body.data.length - 1; i++) {
        expect(body.data[i].total_amount_cents).toBeGreaterThanOrEqual(
          body.data[i + 1].total_amount_cents
        );
      }
    }
  });

  // ========== LIMIT PARAMETER ==========

  test("respects limit parameter", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking?limit=10`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    expect(body.data.length).toBeLessThanOrEqual(10);
  });

  // ========== ANONYMIZATION ==========

  test("anonymize=true produces anonymized names", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking?anonymize=true`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    if (body.data.length > 0) {
      for (const member of body.data) {
        // Anonymized names should follow "Member N" pattern
        expect(member.member_name).toMatch(/^Member \d+$/);
      }
    }
  });

  test("default shows real member names (not anonymized)", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    if (body.data.length > 0) {
      // At least one member name should NOT match "Member N" pattern
      const hasRealName = body.data.some(
        (m: any) => !/^Member \d+$/.test(m.member_name)
      );
      expect(hasRealName).toBe(true);
    }
  });

  // ========== DATE RANGE FILTERING ==========

  test("date range filtering works", async ({
    authenticatedRequest,
  }) => {
    const dateFrom = "2020-01-01";
    const dateTo = "2030-12-31";

    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking?date_from=${dateFrom}&date_to=${dateTo}`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    expect(body).toHaveProperty("data");
    expect(Array.isArray(body.data)).toBe(true);
  });

  // ========== CSV EXPORT ==========
  //
  // The admin panel used to ask this endpoint for `format=csv`, which nothing
  // read, and saved the JSON answer under a .csv name (#94). The export lives
  // at its own path and answers with an actual CSV.

  test("export returns a CSV download, not JSON", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking/export`
    );

    expect(response.status()).toBe(200);
    expect(response.headers()["content-type"]).toContain("text/csv");
    expect(response.headers()["content-disposition"]).toContain("attachment");
    expect(response.headers()["content-disposition"]).toContain(
      "report-member-ranking-"
    );

    const body = await response.text();
    expect(body.startsWith("{")).toBe(false);
    expect(body.startsWith("[")).toBe(false);
  });

  test("export writes the ranking header row", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking/export`
    );

    expect(response.status()).toBe(200);
    const lines = (await response.text()).split("\n");
    expect(lines[0]).toBe("Rank;Member;Total EUR;Transactions");
  });

  test("export honours the limit filter", async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking/export?limit=1`
    );

    expect(response.status()).toBe(200);
    const dataLines = (await response.text())
      .split("\n")
      .slice(1)
      .filter((line) => line.length > 0);
    expect(dataLines.length).toBeLessThanOrEqual(1);
  });

  test("export writes money as decimal euros", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking/export?limit=100`
    );

    expect(response.status()).toBe(200);
    const dataLines = (await response.text())
      .split("\n")
      .slice(1)
      .filter((line) => line.length > 0);

    // Column 3 of every row is the amount; a header-only file passes vacuously
    // rather than being skipped.
    for (const line of dataLines) {
      const fields = line.split(";");
      expect(fields[fields.length - 2]).toMatch(/^-?\d+\.\d{2}$/);
    }
  });

  test("export requires authentication", async ({ request }) => {
    const response = await request.get(
      `${API_BASE}/admin/reports/member-ranking/export`
    );

    expect(response.status()).toBe(401);
  });
});
