import { test, expect } from "../../fixtures/auth.fixture";

const API_BASE = "http://localhost:8080/api";

/**
 * Member Ranking API Tests
 *
 * Tests for GET /api/admin/reports/member-ranking endpoint.
 * Returns members ranked by total consumption amount.
 *
 * Query params: date_from, date_to, limit (10|25|50|100)
 *
 * The ranking is always anonymous — there is no named mode (#177).
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
  //
  // The ranking has no named mode (#177). A named leaderboard of who drinks
  // most is the consumption profile ADR-0029 prohibits, so it was removed
  // rather than gated — and the label is the ordinal position within a single
  // response, never a stable alias that two reports could be joined on.

  /**
   * A member whose purchase is big enough to land at rank 1, so the top of the
   * ranking is a name this test knows and can go looking for. Returns the
   * surname, which is unique per run (Pattern 001).
   */
  async function seedTopSpender(
    authenticatedRequest: any,
    testTransactions: any
  ): Promise<string> {
    const testId = `rank-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const lastName = `Topspender${testId}`;
    const response = await authenticatedRequest.post(
      `${API_BASE}/admin/members`,
      {
        data: {
          first_name: "Rankcheck",
          last_name: lastName,
          email: `${testId}@test.example`,
          iban: "DE89370400440532013000",
          mandate_signed_at: "2024-12-15",
          preferred_language: "de",
        },
      }
    );
    expect(response.status()).toBe(201);
    const member = await response.json();

    // Well clear of the small amounts the other suites book, so this member
    // sits at the top of the ranking and is inside any limit the test asks for.
    await testTransactions.createSyncTransaction(
      member.id,
      100_000,
      `ranking seed ${testId}`
    );

    return lastName;
  }

  test("labels every row by rank and leaks no member name", async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    const lastName = await seedTopSpender(authenticatedRequest, testTransactions);

    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking?limit=100`
    );

    expect(response.status()).toBe(200);
    const raw = await response.text();
    const body = JSON.parse(raw);

    // The seeded member outspends everyone, so the ranking is non-empty and
    // this assertion is never vacuous.
    expect(body.data.length).toBeGreaterThan(0);
    for (const member of body.data) {
      expect(member.member_name).toBe(`Member ${member.rank}`);
    }

    // The name that is definitely in the ranking is nowhere in the response.
    expect(raw).not.toContain(lastName);
    expect(raw).not.toContain("Rankcheck");
  });

  test("an anonymize=false parameter does not bring names back", async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    const lastName = await seedTopSpender(authenticatedRequest, testTransactions);

    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking?limit=100&anonymize=false`
    );

    expect(response.status()).toBe(200);
    const raw = await response.text();
    const body = JSON.parse(raw);

    expect(body.data.length).toBeGreaterThan(0);
    for (const member of body.data) {
      expect(member.member_name).toBe(`Member ${member.rank}`);
    }
    expect(raw).not.toContain(lastName);
  });

  test("export writes the same ordinal labels, not names", async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    const lastName = await seedTopSpender(authenticatedRequest, testTransactions);

    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/member-ranking/export?limit=100&anonymize=false`
    );

    expect(response.status()).toBe(200);
    const csv = await response.text();
    const dataLines = csv
      .split("\n")
      .slice(1)
      .filter((line) => line.length > 0);

    expect(dataLines.length).toBeGreaterThan(0);
    dataLines.forEach((line, index) => {
      const fields = line.split(";");
      expect(fields[0]).toBe(String(index + 1));
      expect(fields[1]).toBe(`Member ${index + 1}`);
    });
    expect(csv).not.toContain(lastName);
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
