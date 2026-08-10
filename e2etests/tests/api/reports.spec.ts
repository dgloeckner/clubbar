import { test, expect } from "../../fixtures/auth.fixture";

const API_BASE = "http://localhost:8080/api";

/**
 * Reports API Tests
 *
 * Tests for GET /api/admin/reports/{reportType} and
 * GET /api/admin/reports/{reportType}/export endpoints.
 *
 * Report types: revenue, consumption, transactions
 * Group-by dimensions: category, product, member, day, week, month, year
 *
 * Test Data Isolation (E2E Pattern 001):
 * - Tests use existing seeded data (read-only endpoints)
 * - Database-agnostic assertions (verify structure, not specific values)
 *
 * Authentication (E2E Pattern 002):
 * - All tests require admin session authentication
 */

test.describe("Reports API", () => {
  // ========== SCHEMA VALIDATION ==========

  test("GET /api/admin/reports/revenue grouped by month returns correct schema", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/revenue?group_by=month`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    // Verify top-level sections
    expect(body).toHaveProperty("metadata");
    expect(body).toHaveProperty("summary");
    expect(body).toHaveProperty("data");
    expect(body).toHaveProperty("pagination");

    // Verify metadata
    expect(body.metadata).toHaveProperty("report_type", "revenue");
    expect(body.metadata).toHaveProperty("generated_at");
    expect(body.metadata).toHaveProperty("filters");

    // Verify summary fields
    expect(body.summary).toHaveProperty("total_revenue_cents");
    expect(body.summary).toHaveProperty("unique_member_count");
    expect(body.summary).toHaveProperty("transaction_count");
    expect(body.summary).toHaveProperty("avg_transaction_cents");

    // Verify pagination
    expect(body.pagination).toHaveProperty("page");
    expect(body.pagination).toHaveProperty("per_page");
    expect(body.pagination).toHaveProperty("total");
    expect(body.pagination).toHaveProperty("total_pages");

    // Verify data is an array
    expect(Array.isArray(body.data)).toBe(true);
  });

  test("GET /api/admin/reports/consumption grouped by product returns correct schema", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/consumption?group_by=product`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    expect(body).toHaveProperty("metadata");
    expect(body.metadata).toHaveProperty("report_type", "consumption");
    expect(body).toHaveProperty("summary");
    expect(body).toHaveProperty("data");
    expect(body).toHaveProperty("pagination");
    expect(Array.isArray(body.data)).toBe(true);
  });

  test("GET /api/admin/reports/consumption sorted by count descending", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/consumption?group_by=product&date_from=2020-01-01&date_to=2030-12-31`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    expect(body).toHaveProperty("metadata");
    expect(body.metadata).toHaveProperty("report_type", "consumption");
    expect(body).toHaveProperty("summary");
    expect(body).toHaveProperty("data");
    expect(Array.isArray(body.data)).toBe(true);

    // Verify rows are sorted by count descending
    if (body.data.length > 1) {
      for (let i = 0; i < body.data.length - 1; i++) {
        expect(body.data[i].count).toBeGreaterThanOrEqual(body.data[i + 1].count);
      }
    }
  });

  // ========== DATA ROW FIELDS ==========

  test("data rows have all required fields", async ({
    authenticatedRequest,
  }) => {
    // Use a wide date range to ensure we get some data
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/revenue?group_by=month&date_from=2020-01-01&date_to=2030-12-31`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    if (body.data.length > 0) {
      const row = body.data[0];
      expect(row).toHaveProperty("dimension");
      expect(row).toHaveProperty("revenue_cents");
      expect(row).toHaveProperty("count");
      expect(row).toHaveProperty("percent_of_total");

      // Verify types
      expect(typeof row.dimension).toBe("string");
      expect(typeof row.revenue_cents).toBe("number");
      expect(typeof row.count).toBe("number");
      expect(typeof row.percent_of_total).toBe("number");
    }
  });

  // ========== DATE RANGE FILTERING ==========

  test("date range filtering works (filters applied in response)", async ({
    authenticatedRequest,
  }) => {
    const dateFrom = "2024-01-01";
    const dateTo = "2024-12-31";

    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/revenue?group_by=month&date_from=${dateFrom}&date_to=${dateTo}`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    // Filters should be reflected in metadata
    expect(body.metadata.filters).toBeDefined();
  });

  // ========== PAGINATION ==========

  test("pagination works (page/per_page respected)", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/revenue?group_by=month&page=1&per_page=5`
    );

    expect(response.status()).toBe(200);
    const body = await response.json();

    expect(body.pagination.page).toBe(1);
    expect(body.pagination.per_page).toBe(5);
    expect(body.data.length).toBeLessThanOrEqual(5);
  });

  // ========== GROUP-BY DIMENSIONS ==========

  const groupByDimensions = ["category", "product", "member", "day", "week", "month", "year"];

  for (const dimension of groupByDimensions) {
    test(`group_by=${dimension} returns 200`, async ({
      authenticatedRequest,
    }) => {
      const response = await authenticatedRequest.get(
        `${API_BASE}/admin/reports/revenue?group_by=${dimension}`
      );

      expect(response.status()).toBe(200);
      const body = await response.json();
      expect(body).toHaveProperty("metadata");
      expect(body).toHaveProperty("data");
    });
  }

  // ========== ERROR HANDLING ==========

  test("invalid report type returns 400", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/invalid_type?group_by=month`
    );

    // Could be 400 or 404 depending on routing
    expect([400, 404]).toContain(response.status());
  });

  test("invalid group_by returns 400", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/revenue?group_by=invalid_dimension`
    );

    expect([400, 422]).toContain(response.status());
  });

  // ========== CSV EXPORT ==========

  test("CSV export returns text/csv content type", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/revenue/export?group_by=month`
    );

    expect(response.ok()).toBeTruthy();
    const contentType = response.headers()["content-type"];
    expect(contentType).toContain("text/csv");
  });

  test("CSV export has Content-Disposition header", async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/revenue/export?group_by=month`
    );

    expect(response.ok()).toBeTruthy();
    const contentDisposition = response.headers()["content-disposition"];
    expect(contentDisposition).toBeDefined();
    expect(contentDisposition).toContain("attachment");
  });

  // #104: only the revenue export was exercised; consumption shares the
  // export code path but had no coverage of its own report_type or rows.
  test("consumption CSV export returns text/csv with the report's rows", async ({
    authenticatedRequest,
  }) => {
    // per_page matches what exportCsv uses internally, so the row count
    // comparison below isn't at the mercy of the JSON endpoint's default page size.
    const jsonResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/consumption?group_by=product&per_page=10000`
    );
    expect(jsonResponse.ok()).toBeTruthy();
    const jsonBody = await jsonResponse.json();
    expect(jsonBody.metadata).toHaveProperty("report_type", "consumption");

    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/reports/consumption/export?group_by=product`
    );

    expect(response.ok()).toBeTruthy();
    expect(response.headers()["content-type"]).toContain("text/csv");
    const contentDisposition = response.headers()["content-disposition"];
    expect(contentDisposition).toBeDefined();
    expect(contentDisposition).toContain("attachment");

    const csv = await response.text();
    const lines = csv.trim().split("\n");
    expect(lines[0]).toContain("Dimension");
    // One header row plus one row per dimension returned by the JSON report.
    expect(lines.length).toBe(jsonBody.data.length + 1);
  });
});
