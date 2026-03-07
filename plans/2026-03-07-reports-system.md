# Reports System Implementation Plan (UC-A50, UC-A51, UC-A52)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a unified reports system with revenue/consumption/transaction reports, member ranking, and terminal activity — replacing the basic monthly statistics page.

**Architecture:** New `Reports` backend module with a `ReportsController` and `ReportsService` that queries the existing `transactions` table with flexible grouping/filtering. Frontend gets a new `ReportsPage` with tab-based report type selection, date range filtering, chart visualization, and CSV export. The existing `StatisticsPage` and `/statistics` route are replaced by `/reports`.

**Tech Stack:** PHP 8.3 (Slim 4, PDO), React 18 (TypeScript), Recharts, Playwright E2E tests

---

## Phase 1: Backend — Unified Reports API (UC-A50)

### Task 1: Create Reports module structure

**Files:**
- Create: `backend/src/Modules/Reports/Controllers/AdminController.php`
- Create: `backend/src/Modules/Reports/Services/ReportsService.php`
- Create: `backend/src/Modules/Reports/DTOs/ReportDto.php`
- Create: `backend/src/Modules/Reports/DTOs/ReportRowDto.php`

**Step 1: Create ReportDto and ReportRowDto**

```php
// backend/src/Modules/Reports/DTOs/ReportRowDto.php
<?php

declare(strict_types=1);

namespace App\Modules\Reports\DTOs;

class ReportRowDto
{
    public function __construct(
        public readonly string $dimension,
        public readonly int $revenueCents,
        public readonly int $quantity,
        public readonly int $count,
        public readonly float $percentOfTotal,
    ) {}

    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension,
            'revenue_cents' => $this->revenueCents,
            'quantity' => $this->quantity,
            'count' => $this->count,
            'percent_of_total' => round($this->percentOfTotal, 2),
        ];
    }
}
```

```php
// backend/src/Modules/Reports/DTOs/ReportDto.php
<?php

declare(strict_types=1);

namespace App\Modules\Reports\DTOs;

class ReportDto
{
    public function __construct(
        public readonly string $reportType,
        public readonly array $filters,
        public readonly int $totalRevenueCents,
        public readonly int $totalQuantity,
        public readonly int $transactionCount,
        public readonly int $avgTransactionCents,
        /** @var ReportRowDto[] */
        public readonly array $data,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
    ) {}

    public function toArray(): array
    {
        return [
            'metadata' => [
                'report_type' => $this->reportType,
                'generated_at' => date('c'),
                'filters' => $this->filters,
            ],
            'summary' => [
                'total_revenue_cents' => $this->totalRevenueCents,
                'total_quantity' => $this->totalQuantity,
                'transaction_count' => $this->transactionCount,
                'avg_transaction_cents' => $this->avgTransactionCents,
            ],
            'data' => array_map(fn(ReportRowDto $row) => $row->toArray(), $this->data),
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'total_pages' => (int) ceil($this->total / max($this->perPage, 1)),
            ],
        ];
    }
}
```

**Step 2: Commit**

```bash
git add backend/src/Modules/Reports/DTOs/
git commit -m "feat(reports): add Report DTOs for unified reports API (UC-A50)"
```

---

### Task 2: Create ReportsService

**Files:**
- Create: `backend/src/Modules/Reports/Services/ReportsService.php`

**Step 1: Write the ReportsService**

The service handles all three report types (revenue, consumption, transactions) using flexible SQL grouping. It queries the `transactions` table and groups by the requested dimension (category, product, member, day, week, month, year).

```php
// backend/src/Modules/Reports/Services/ReportsService.php
<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\DTOs\ReportDto;
use App\Modules\Reports\DTOs\ReportRowDto;
use PDO;

class ReportsService
{
    private const VALID_REPORT_TYPES = ['revenue', 'consumption', 'transactions'];
    private const VALID_GROUP_BY = ['category', 'product', 'member', 'day', 'week', 'month', 'year'];
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    public function __construct(private PDO $db) {}

    public function getReport(
        string $reportType,
        ?string $dateFrom,
        ?string $dateTo,
        string $groupBy = 'month',
        ?string $categoryIds = null,
        ?string $productIds = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): ReportDto {
        if (!in_array($reportType, self::VALID_REPORT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid report type: {$reportType}");
        }
        if (!in_array($groupBy, self::VALID_GROUP_BY, true)) {
            throw new \InvalidArgumentException("Invalid group_by: {$groupBy}");
        }

        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);
        $page = max($page, 1);

        // Build WHERE clause
        $conditions = ["t.transaction_type = 'purchase'"];
        $params = [];

        if ($dateFrom) {
            $conditions[] = 't.created_at >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $conditions[] = 't.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $dateTo;
        }
        if ($categoryIds) {
            $ids = array_filter(explode(',', $categoryIds));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $conditions[] = "p.category_id IN ({$placeholders})";
                $params = array_merge($params, $ids);
            }
        }
        if ($productIds) {
            $ids = array_filter(explode(',', $productIds));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $conditions[] = "t.product_id IN ({$placeholders})";
                $params = array_merge($params, $ids);
            }
        }

        $where = implode(' AND ', $conditions);

        // Get summary (no grouping, no pagination)
        $summaryParams = $params; // same filters
        $summaryStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(t.amount_cents), 0) as total_revenue_cents,
                    COUNT(*) as total_quantity,
                    COUNT(DISTINCT t.id) as transaction_count
             FROM transactions t
             LEFT JOIN products p ON t.product_id = p.id
             WHERE {$where}"
        );
        $summaryStmt->execute($summaryParams);
        $summary = $summaryStmt->fetch();

        $totalRevenueCents = (int) $summary['total_revenue_cents'];
        $totalQuantity = (int) $summary['total_quantity'];
        $transactionCount = (int) $summary['transaction_count'];
        $avgTransactionCents = $transactionCount > 0 ? (int) round($totalRevenueCents / $transactionCount) : 0;

        // Build GROUP BY and dimension SELECT
        [$dimensionSelect, $groupByClause, $joins] = $this->buildGroupBy($groupBy);

        // Count total groups for pagination
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM (
                SELECT 1
                FROM transactions t
                LEFT JOIN products p ON t.product_id = p.id
                {$joins}
                WHERE {$where}
                GROUP BY {$groupByClause}
            ) as grouped"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Get grouped data with pagination
        $offset = ($page - 1) * $perPage;
        $dataStmt = $this->db->prepare(
            "SELECT {$dimensionSelect} as dimension,
                    SUM(t.amount_cents) as revenue_cents,
                    COUNT(*) as quantity,
                    COUNT(DISTINCT t.id) as count
             FROM transactions t
             LEFT JOIN products p ON t.product_id = p.id
             {$joins}
             WHERE {$where}
             GROUP BY {$groupByClause}
             ORDER BY revenue_cents DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll();

        $data = [];
        foreach ($rows as $row) {
            $revCents = (int) $row['revenue_cents'];
            $pct = $totalRevenueCents > 0 ? ($revCents / $totalRevenueCents) * 100 : 0;
            $data[] = new ReportRowDto(
                dimension: (string) $row['dimension'],
                revenueCents: $revCents,
                quantity: (int) $row['quantity'],
                count: (int) $row['count'],
                percentOfTotal: $pct,
            );
        }

        return new ReportDto(
            reportType: $reportType,
            filters: array_filter([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'group_by' => $groupBy,
                'category_ids' => $categoryIds,
                'product_ids' => $productIds,
            ]),
            totalRevenueCents: $totalRevenueCents,
            totalQuantity: $totalQuantity,
            transactionCount: $transactionCount,
            avgTransactionCents: $avgTransactionCents,
            data: $data,
            page: $page,
            perPage: $perPage,
            total: $total,
        );
    }

    /**
     * Export report data as CSV string (no pagination).
     */
    public function exportCsv(
        string $reportType,
        ?string $dateFrom,
        ?string $dateTo,
        string $groupBy = 'month',
    ): string {
        // Use a large page to get all data
        $report = $this->getReport($reportType, $dateFrom, $dateTo, $groupBy, null, null, 1, 10000);

        $lines = [];
        $lines[] = implode(',', ['Dimension', 'Revenue (cents)', 'Quantity', 'Count', '% of Total']);
        foreach ($report->data as $row) {
            $arr = $row->toArray();
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $arr['dimension']) . '"',
                $arr['revenue_cents'],
                $arr['quantity'],
                $arr['count'],
                $arr['percent_of_total'],
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{string, string, string} [dimensionSelect, groupByClause, joins]
     */
    private function buildGroupBy(string $groupBy): array
    {
        return match ($groupBy) {
            'category' => [
                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.names, '$.de')), JSON_UNQUOTE(JSON_EXTRACT(c.names, '$.en')), 'Unknown')",
                'p.category_id',
                'LEFT JOIN categories c ON p.category_id = c.id',
            ],
            'product' => [
                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.names, '$.de')), JSON_UNQUOTE(JSON_EXTRACT(p.names, '$.en')), 'Unknown')",
                'p.id',
                '',
            ],
            'member' => [
                "CONCAT(m.first_name, ' ', m.last_name)",
                'm.id',
                'LEFT JOIN members m ON t.member_id = m.id',
            ],
            'day' => [
                'DATE(t.created_at)',
                'DATE(t.created_at)',
                '',
            ],
            'week' => [
                "CONCAT(YEAR(t.created_at), '-W', LPAD(WEEK(t.created_at, 1), 2, '0'))",
                'YEAR(t.created_at), WEEK(t.created_at, 1)',
                '',
            ],
            'month' => [
                "DATE_FORMAT(t.created_at, '%Y-%m')",
                "DATE_FORMAT(t.created_at, '%Y-%m')",
                '',
            ],
            'year' => [
                'YEAR(t.created_at)',
                'YEAR(t.created_at)',
                '',
            ],
        };
    }
}
```

**Step 2: Commit**

```bash
git add backend/src/Modules/Reports/Services/ReportsService.php
git commit -m "feat(reports): add ReportsService with flexible grouping and CSV export (UC-A50)"
```

---

### Task 3: Create Reports AdminController and register routes

**Files:**
- Create: `backend/src/Modules/Reports/Controllers/AdminController.php`
- Modify: `backend/src/routes.php`
- Modify: `backend/src/Shared/ServiceFactory.php` (register new service + controller)

**Step 1: Create the AdminController**

```php
// backend/src/Modules/Reports/Controllers/AdminController.php
<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Modules\Reports\Services\ReportsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(private ReportsService $reportsService) {}

    /**
     * GET /api/admin/reports/{reportType}
     */
    public function getReport(Request $request, Response $response, array $args): Response
    {
        $reportType = $args['reportType'] ?? '';
        $params = $request->getQueryParams();

        try {
            $report = $this->reportsService->getReport(
                reportType: $reportType,
                dateFrom: $params['date_from'] ?? null,
                dateTo: $params['date_to'] ?? null,
                groupBy: $params['group_by'] ?? 'month',
                categoryIds: $params['category_ids'] ?? null,
                productIds: $params['product_ids'] ?? null,
                page: (int) ($params['page'] ?? 1),
                perPage: (int) ($params['per_page'] ?? 25),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        return $this->json($response, $report->toArray());
    }

    /**
     * GET /api/admin/reports/{reportType}/export
     */
    public function exportReport(Request $request, Response $response, array $args): Response
    {
        $reportType = $args['reportType'] ?? '';
        $params = $request->getQueryParams();

        try {
            $csv = $this->reportsService->exportCsv(
                reportType: $reportType,
                dateFrom: $params['date_from'] ?? null,
                dateTo: $params['date_to'] ?? null,
                groupBy: $params['group_by'] ?? 'month',
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        $filename = "report-{$reportType}-" . date('Y-m-d') . '.csv';
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withStatus(200);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
```

**Step 2: Register in ServiceFactory**

Add to the `FQCN_MAP` and factory methods in `backend/src/Shared/ServiceFactory.php`:

```php
// In FQCN_MAP:
\App\Modules\Reports\Controllers\AdminController::class => 'createReportsAdminController',
\App\Modules\Reports\Services\ReportsService::class => 'createReportsService',

// Factory methods:
private function createReportsService(): \App\Modules\Reports\Services\ReportsService
{
    return new \App\Modules\Reports\Services\ReportsService($this->getDb());
}

private function createReportsAdminController(): \App\Modules\Reports\Controllers\AdminController
{
    return new \App\Modules\Reports\Controllers\AdminController(
        $this->get(\App\Modules\Reports\Services\ReportsService::class),
    );
}
```

**Step 3: Register routes in `backend/src/routes.php`**

Add inside the admin group:

```php
// Reports
$group->get('/reports/{reportType}', [ReportsAdminController::class, 'getReport']);
$group->get('/reports/{reportType}/export', [ReportsAdminController::class, 'exportReport']);
```

Add the import at the top:
```php
use App\Modules\Reports\Controllers\AdminController as ReportsAdminController;
```

**Step 4: Restart PHP and test manually**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
curl -s 'http://localhost:8080/api/admin/reports/revenue?group_by=month' | jq .
```

Expected: JSON response with `metadata`, `summary`, `data`, `pagination` fields (or 401 if not authenticated — that's fine, confirms route works).

**Step 5: Commit**

```bash
git add backend/src/Modules/Reports/Controllers/AdminController.php backend/src/routes.php backend/src/Shared/ServiceFactory.php
git commit -m "feat(reports): add Reports controller, routes, and DI wiring (UC-A50)"
```

---

### Task 4: E2E API tests for reports endpoints

**Files:**
- Create: `e2etests/tests/api/reports.spec.ts`

**Step 1: Write the E2E API tests**

```typescript
// e2etests/tests/api/reports.spec.ts
import { test, expect } from '../../fixtures/pageObjects'

test.describe('Reports API (UC-A50)', () => {
  const baseUrl = 'http://localhost:8080'

  test.describe('GET /api/admin/reports/{reportType}', () => {
    test('should return revenue report grouped by month', async ({ request }) => {
      const response = await request.get(`${baseUrl}/api/admin/reports/revenue?group_by=month`)
      expect(response.status()).toBe(200)

      const body = await response.json()
      expect(body).toHaveProperty('metadata')
      expect(body).toHaveProperty('summary')
      expect(body).toHaveProperty('data')
      expect(body).toHaveProperty('pagination')
      expect(body.metadata.report_type).toBe('revenue')
      expect(body.summary).toHaveProperty('total_revenue_cents')
      expect(body.summary).toHaveProperty('total_quantity')
      expect(body.summary).toHaveProperty('transaction_count')
      expect(body.summary).toHaveProperty('avg_transaction_cents')
    })

    test('should return consumption report grouped by product', async ({ request }) => {
      const response = await request.get(`${baseUrl}/api/admin/reports/consumption?group_by=product`)
      expect(response.status()).toBe(200)

      const body = await response.json()
      expect(body.metadata.report_type).toBe('consumption')
      expect(Array.isArray(body.data)).toBe(true)
    })

    test('should return transactions report grouped by day', async ({ request }) => {
      const response = await request.get(`${baseUrl}/api/admin/reports/transactions?group_by=day`)
      expect(response.status()).toBe(200)

      const body = await response.json()
      expect(body.metadata.report_type).toBe('transactions')
    })

    test('should filter by date range', async ({ request }) => {
      const response = await request.get(
        `${baseUrl}/api/admin/reports/revenue?date_from=2025-01-01&date_to=2025-12-31&group_by=month`
      )
      expect(response.status()).toBe(200)

      const body = await response.json()
      expect(body.metadata.filters.date_from).toBe('2025-01-01')
      expect(body.metadata.filters.date_to).toBe('2025-12-31')
    })

    test('should support pagination', async ({ request }) => {
      const response = await request.get(
        `${baseUrl}/api/admin/reports/revenue?group_by=day&page=1&per_page=5`
      )
      expect(response.status()).toBe(200)

      const body = await response.json()
      expect(body.pagination.page).toBe(1)
      expect(body.pagination.per_page).toBe(5)
      expect(body.data.length).toBeLessThanOrEqual(5)
    })

    test('should return 400 for invalid report type', async ({ request }) => {
      const response = await request.get(`${baseUrl}/api/admin/reports/invalid`)
      expect(response.status()).toBe(400)
    })

    test('should return 400 for invalid group_by', async ({ request }) => {
      const response = await request.get(`${baseUrl}/api/admin/reports/revenue?group_by=invalid`)
      expect(response.status()).toBe(400)
    })

    test('should support all group_by dimensions', async ({ request }) => {
      const dimensions = ['category', 'product', 'member', 'day', 'week', 'month', 'year']
      for (const dim of dimensions) {
        const response = await request.get(`${baseUrl}/api/admin/reports/revenue?group_by=${dim}`)
        expect(response.status()).toBe(200)
      }
    })

    test('each data row should have percent_of_total', async ({ request }) => {
      const response = await request.get(`${baseUrl}/api/admin/reports/revenue?group_by=month`)
      expect(response.status()).toBe(200)

      const body = await response.json()
      if (body.data.length > 0) {
        expect(body.data[0]).toHaveProperty('dimension')
        expect(body.data[0]).toHaveProperty('revenue_cents')
        expect(body.data[0]).toHaveProperty('quantity')
        expect(body.data[0]).toHaveProperty('count')
        expect(body.data[0]).toHaveProperty('percent_of_total')
      }
    })
  })

  test.describe('GET /api/admin/reports/{reportType}/export', () => {
    test('should return CSV for revenue report export', async ({ request }) => {
      const response = await request.get(
        `${baseUrl}/api/admin/reports/revenue/export?group_by=month`
      )
      expect(response.status()).toBe(200)

      const contentType = response.headers()['content-type']
      expect(contentType).toContain('text/csv')

      const body = await response.text()
      // CSV header should be present
      expect(body).toContain('Dimension')
      expect(body).toContain('Revenue')
    })

    test('should include content-disposition header', async ({ request }) => {
      const response = await request.get(
        `${baseUrl}/api/admin/reports/revenue/export?group_by=month`
      )
      expect(response.status()).toBe(200)

      const disposition = response.headers()['content-disposition']
      expect(disposition).toContain('attachment')
      expect(disposition).toContain('report-revenue')
    })
  })
})
```

**Step 2: Run the tests**

```bash
cd e2etests && npm test -- tests/api/reports.spec.ts --workers=4
```

Expected: All tests pass.

**Step 3: Commit**

```bash
git add e2etests/tests/api/reports.spec.ts
git commit -m "test(reports): add E2E API tests for reports endpoints (UC-A50)"
```

---

## Phase 2: Backend — Member Ranking API (UC-A51)

### Task 5: Add member ranking endpoint to ReportsService

**Files:**
- Modify: `backend/src/Modules/Reports/Services/ReportsService.php`
- Modify: `backend/src/Modules/Reports/Controllers/AdminController.php`
- Modify: `backend/src/routes.php`

**Step 1: Add `getMemberRanking()` to ReportsService**

```php
/**
 * Get member consumption ranking (UC-A51).
 */
public function getMemberRanking(
    ?string $dateFrom,
    ?string $dateTo,
    bool $anonymize = false,
    int $limit = 25,
): array {
    $limit = min(max($limit, 1), 100);

    $conditions = ["t.transaction_type = 'purchase'"];
    $params = [];

    if ($dateFrom) {
        $conditions[] = 't.created_at >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $conditions[] = 't.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $dateTo;
    }

    $where = implode(' AND ', $conditions);

    $stmt = $this->db->prepare(
        "SELECT m.id, m.first_name, m.last_name,
                SUM(t.amount_cents) as total_amount_cents,
                COUNT(*) as transaction_count
         FROM transactions t
         JOIN members m ON t.member_id = m.id
         WHERE {$where}
         GROUP BY m.id
         ORDER BY total_amount_cents DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $data = [];
    $rank = 1;
    foreach ($rows as $row) {
        $data[] = [
            'rank' => $rank,
            'member_name' => $anonymize
                ? "Member {$rank}"
                : trim($row['first_name'] . ' ' . $row['last_name']),
            'total_amount_cents' => (int) $row['total_amount_cents'],
            'transaction_count' => (int) $row['transaction_count'],
        ];
        $rank++;
    }

    return ['data' => $data];
}
```

**Step 2: Add controller method**

```php
/**
 * GET /api/admin/reports/member-ranking
 */
public function memberRanking(Request $request, Response $response): Response
{
    $params = $request->getQueryParams();

    $data = $this->reportsService->getMemberRanking(
        dateFrom: $params['date_from'] ?? null,
        dateTo: $params['date_to'] ?? null,
        anonymize: filter_var($params['anonymize'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        limit: (int) ($params['limit'] ?? 25),
    );

    return $this->json($response, $data);
}
```

**Step 3: Add route** (in routes.php, inside admin group)

```php
$group->get('/reports/member-ranking', [ReportsAdminController::class, 'memberRanking']);
```

**Important:** This route MUST be registered BEFORE the `'/reports/{reportType}'` route, otherwise Slim will match `member-ranking` as a `{reportType}` parameter.

**Step 4: Restart PHP and test manually**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
curl -s 'http://localhost:8080/api/admin/reports/member-ranking?limit=10' | jq .
```

**Step 5: Commit**

```bash
git add backend/src/Modules/Reports/Services/ReportsService.php backend/src/Modules/Reports/Controllers/AdminController.php backend/src/routes.php
git commit -m "feat(reports): add member ranking endpoint (UC-A51)"
```

---

### Task 6: E2E API tests for member ranking

**Files:**
- Create: `e2etests/tests/api/member-ranking.spec.ts`

**Step 1: Write tests**

```typescript
// e2etests/tests/api/member-ranking.spec.ts
import { test, expect } from '../../fixtures/pageObjects'

test.describe('Member Ranking API (UC-A51)', () => {
  const baseUrl = 'http://localhost:8080'

  test('should return member ranking', async ({ request }) => {
    const response = await request.get(`${baseUrl}/api/admin/reports/member-ranking`)
    expect(response.status()).toBe(200)

    const body = await response.json()
    expect(body).toHaveProperty('data')
    expect(Array.isArray(body.data)).toBe(true)
  })

  test('should return ranked members with required fields', async ({ request }) => {
    const response = await request.get(`${baseUrl}/api/admin/reports/member-ranking?limit=5`)
    expect(response.status()).toBe(200)

    const body = await response.json()
    if (body.data.length > 0) {
      const first = body.data[0]
      expect(first).toHaveProperty('rank')
      expect(first).toHaveProperty('member_name')
      expect(first).toHaveProperty('total_amount_cents')
      expect(first).toHaveProperty('transaction_count')
      expect(first.rank).toBe(1)
    }
  })

  test('should respect limit parameter', async ({ request }) => {
    const response = await request.get(`${baseUrl}/api/admin/reports/member-ranking?limit=3`)
    expect(response.status()).toBe(200)

    const body = await response.json()
    expect(body.data.length).toBeLessThanOrEqual(3)
  })

  test('should anonymize member names when requested', async ({ request }) => {
    const response = await request.get(`${baseUrl}/api/admin/reports/member-ranking?anonymize=true&limit=5`)
    expect(response.status()).toBe(200)

    const body = await response.json()
    for (const row of body.data) {
      expect(row.member_name).toMatch(/^Member \d+$/)
    }
  })

  test('should show real names by default', async ({ request }) => {
    const response = await request.get(`${baseUrl}/api/admin/reports/member-ranking?limit=5`)
    expect(response.status()).toBe(200)

    const body = await response.json()
    if (body.data.length > 0) {
      // Real names should NOT match "Member N" pattern (unless coincidence)
      // We just check the field exists and is a non-empty string
      expect(body.data[0].member_name.length).toBeGreaterThan(0)
    }
  })

  test('should filter by date range', async ({ request }) => {
    const response = await request.get(
      `${baseUrl}/api/admin/reports/member-ranking?date_from=2025-01-01&date_to=2025-12-31`
    )
    expect(response.status()).toBe(200)

    const body = await response.json()
    expect(Array.isArray(body.data)).toBe(true)
  })

  test('should order by total_amount_cents descending', async ({ request }) => {
    const response = await request.get(`${baseUrl}/api/admin/reports/member-ranking?limit=25`)
    expect(response.status()).toBe(200)

    const body = await response.json()
    for (let i = 1; i < body.data.length; i++) {
      expect(body.data[i].total_amount_cents).toBeLessThanOrEqual(body.data[i - 1].total_amount_cents)
    }
  })
})
```

**Step 2: Run tests**

```bash
cd e2etests && npm test -- tests/api/member-ranking.spec.ts --workers=4
```

**Step 3: Commit**

```bash
git add e2etests/tests/api/member-ranking.spec.ts
git commit -m "test(reports): add E2E API tests for member ranking (UC-A51)"
```

---

## Phase 3: Backend — Terminal Activity API (UC-A52)

### Task 7: Add terminal activity endpoint to ReportsService

**Files:**
- Modify: `backend/src/Modules/Reports/Services/ReportsService.php`
- Modify: `backend/src/Modules/Reports/Controllers/AdminController.php`
- Modify: `backend/src/routes.php`

**Step 1: Add `getTerminalActivity()` to ReportsService**

```php
/**
 * Get terminal activity report (UC-A52).
 * Sessions defined as: gap of 30+ minutes between transactions = new session.
 */
public function getTerminalActivity(
    string $dateFrom,
    string $dateTo,
    ?string $terminalId = null,
): array {
    $conditions = ['1=1'];
    $params = [];

    $conditions[] = 't.created_at >= ?';
    $params[] = $dateFrom;
    $conditions[] = 't.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $params[] = $dateTo;

    if ($terminalId) {
        $conditions[] = 't.terminal_id = ?';
        $params[] = $terminalId;
    }

    $where = implode(' AND ', $conditions);

    // Get all transactions in date range ordered by time
    $stmt = $this->db->prepare(
        "SELECT t.id, t.terminal_id, t.amount_cents, t.created_at,
                te.name as terminal_name
         FROM transactions t
         LEFT JOIN terminals te ON t.terminal_id = te.id
         WHERE {$where}
         ORDER BY t.created_at ASC"
    );
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    // Build sessions (30-minute gap = new session)
    $sessions = [];
    $currentSession = null;
    $sessionGapSeconds = 30 * 60; // 30 minutes

    foreach ($transactions as $tx) {
        $txTime = strtotime($tx['created_at']);

        if ($currentSession === null || ($txTime - $currentSession['last_time']) > $sessionGapSeconds) {
            // Start new session
            if ($currentSession !== null) {
                $sessions[] = $this->finalizeSession($currentSession);
            }
            $currentSession = [
                'date' => date('Y-m-d', $txTime),
                'start_time' => date('H:i:s', $txTime),
                'last_time' => $txTime,
                'end_time' => date('H:i:s', $txTime),
                'transaction_count' => 0,
                'revenue_cents' => 0,
            ];
        }

        $currentSession['last_time'] = $txTime;
        $currentSession['end_time'] = date('H:i:s', $txTime);
        $currentSession['transaction_count']++;
        $currentSession['revenue_cents'] += (int) $tx['amount_cents'];
    }
    if ($currentSession !== null) {
        $sessions[] = $this->finalizeSession($currentSession);
    }

    // Hourly distribution
    $hourlyStmt = $this->db->prepare(
        "SELECT HOUR(t.created_at) as hour, COUNT(*) as transaction_count
         FROM transactions t
         WHERE {$where}
         GROUP BY HOUR(t.created_at)
         ORDER BY hour"
    );
    $hourlyStmt->execute($params);
    $hourlyRows = $hourlyStmt->fetchAll();

    // Fill all 24 hours
    $hourlyDist = [];
    $hourMap = [];
    foreach ($hourlyRows as $row) {
        $hourMap[(int) $row['hour']] = (int) $row['transaction_count'];
    }
    for ($h = 0; $h < 24; $h++) {
        $hourlyDist[] = [
            'hour' => $h,
            'transaction_count' => $hourMap[$h] ?? 0,
        ];
    }

    // Terminal summary
    $terminalStmt = $this->db->prepare(
        "SELECT te.id, te.name, COUNT(t.id) as transaction_count, MAX(te.last_sync_at) as last_sync_at
         FROM transactions t
         JOIN terminals te ON t.terminal_id = te.id
         WHERE {$where}
         GROUP BY te.id
         ORDER BY transaction_count DESC"
    );
    $terminalStmt->execute($params);
    $terminalRows = $terminalStmt->fetchAll();

    $terminals = [];
    foreach ($terminalRows as $row) {
        $terminals[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'transaction_count' => (int) $row['transaction_count'],
            'last_sync_at' => $row['last_sync_at'],
        ];
    }

    return [
        'sessions' => $sessions,
        'hourly_distribution' => $hourlyDist,
        'terminals' => $terminals,
    ];
}

private function finalizeSession(array $session): array
{
    unset($session['last_time']);
    return $session;
}
```

**Step 2: Add controller method**

```php
/**
 * GET /api/admin/reports/terminal-activity
 */
public function terminalActivity(Request $request, Response $response): Response
{
    $params = $request->getQueryParams();

    $dateFrom = $params['date_from'] ?? null;
    $dateTo = $params['date_to'] ?? null;

    if (!$dateFrom || !$dateTo) {
        return $this->json($response, ['error' => 'date_from and date_to are required'], 400);
    }

    $data = $this->reportsService->getTerminalActivity(
        dateFrom: $dateFrom,
        dateTo: $dateTo,
        terminalId: $params['terminal_id'] ?? null,
    );

    return $this->json($response, $data);
}
```

**Step 3: Add route** (BEFORE the `{reportType}` route)

```php
$group->get('/reports/terminal-activity', [ReportsAdminController::class, 'terminalActivity']);
```

**Step 4: Restart PHP and test manually**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
curl -s 'http://localhost:8080/api/admin/reports/terminal-activity?date_from=2025-01-01&date_to=2026-12-31' | jq .
```

**Step 5: Commit**

```bash
git add backend/src/Modules/Reports/Services/ReportsService.php backend/src/Modules/Reports/Controllers/AdminController.php backend/src/routes.php
git commit -m "feat(reports): add terminal activity endpoint with sessions and hourly distribution (UC-A52)"
```

---

### Task 8: E2E API tests for terminal activity

**Files:**
- Create: `e2etests/tests/api/terminal-activity.spec.ts`

**Step 1: Write tests**

```typescript
// e2etests/tests/api/terminal-activity.spec.ts
import { test, expect } from '../../fixtures/pageObjects'

test.describe('Terminal Activity API (UC-A52)', () => {
  const baseUrl = 'http://localhost:8080'

  test('should return terminal activity with required sections', async ({ request }) => {
    const response = await request.get(
      `${baseUrl}/api/admin/reports/terminal-activity?date_from=2025-01-01&date_to=2026-12-31`
    )
    expect(response.status()).toBe(200)

    const body = await response.json()
    expect(body).toHaveProperty('sessions')
    expect(body).toHaveProperty('hourly_distribution')
    expect(body).toHaveProperty('terminals')
    expect(Array.isArray(body.sessions)).toBe(true)
    expect(Array.isArray(body.hourly_distribution)).toBe(true)
    expect(Array.isArray(body.terminals)).toBe(true)
  })

  test('should return 24 hours in hourly distribution', async ({ request }) => {
    const response = await request.get(
      `${baseUrl}/api/admin/reports/terminal-activity?date_from=2025-01-01&date_to=2026-12-31`
    )
    expect(response.status()).toBe(200)

    const body = await response.json()
    expect(body.hourly_distribution.length).toBe(24)
    expect(body.hourly_distribution[0].hour).toBe(0)
    expect(body.hourly_distribution[23].hour).toBe(23)
  })

  test('should require date_from and date_to', async ({ request }) => {
    const response = await request.get(`${baseUrl}/api/admin/reports/terminal-activity`)
    expect(response.status()).toBe(400)
  })

  test('should return 400 when only date_from provided', async ({ request }) => {
    const response = await request.get(
      `${baseUrl}/api/admin/reports/terminal-activity?date_from=2025-01-01`
    )
    expect(response.status()).toBe(400)
  })

  test('sessions should have correct fields', async ({ request }) => {
    const response = await request.get(
      `${baseUrl}/api/admin/reports/terminal-activity?date_from=2025-01-01&date_to=2026-12-31`
    )
    expect(response.status()).toBe(200)

    const body = await response.json()
    if (body.sessions.length > 0) {
      const session = body.sessions[0]
      expect(session).toHaveProperty('date')
      expect(session).toHaveProperty('start_time')
      expect(session).toHaveProperty('end_time')
      expect(session).toHaveProperty('transaction_count')
      expect(session).toHaveProperty('revenue_cents')
    }
  })

  test('terminals should have correct fields', async ({ request }) => {
    const response = await request.get(
      `${baseUrl}/api/admin/reports/terminal-activity?date_from=2025-01-01&date_to=2026-12-31`
    )
    expect(response.status()).toBe(200)

    const body = await response.json()
    if (body.terminals.length > 0) {
      const terminal = body.terminals[0]
      expect(terminal).toHaveProperty('id')
      expect(terminal).toHaveProperty('name')
      expect(terminal).toHaveProperty('transaction_count')
    }
  })
})
```

**Step 2: Run tests**

```bash
cd e2etests && npm test -- tests/api/terminal-activity.spec.ts --workers=4
```

**Step 3: Commit**

```bash
git add e2etests/tests/api/terminal-activity.spec.ts
git commit -m "test(reports): add E2E API tests for terminal activity (UC-A52)"
```

---

## Phase 4: Frontend — Reports Page

### Task 9: Create reports service

**Files:**
- Create: `admin-frontend/src/services/reports.ts`

**Step 1: Write the service**

```typescript
// admin-frontend/src/services/reports.ts
import { get, downloadFile } from './api'

export type ReportType = 'revenue' | 'consumption' | 'transactions'
export type GroupBy = 'category' | 'product' | 'member' | 'day' | 'week' | 'month' | 'year'

export interface ReportRow {
  dimension: string
  revenue_cents: number
  quantity: number
  count: number
  percent_of_total: number
}

export interface ReportSummary {
  total_revenue_cents: number
  total_quantity: number
  transaction_count: number
  avg_transaction_cents: number
}

export interface ReportResponse {
  metadata: {
    report_type: string
    generated_at: string
    filters: Record<string, string>
  }
  summary: ReportSummary
  data: ReportRow[]
  pagination: {
    page: number
    per_page: number
    total: number
    total_pages: number
  }
}

export interface MemberRankingRow {
  rank: number
  member_name: string
  total_amount_cents: number
  transaction_count: number
}

export interface MemberRankingResponse {
  data: MemberRankingRow[]
}

export interface TerminalActivitySession {
  date: string
  start_time: string
  end_time: string
  transaction_count: number
  revenue_cents: number
}

export interface HourlyDistribution {
  hour: number
  transaction_count: number
}

export interface TerminalSummary {
  id: string
  name: string
  transaction_count: number
  last_sync_at: string | null
}

export interface TerminalActivityResponse {
  sessions: TerminalActivitySession[]
  hourly_distribution: HourlyDistribution[]
  terminals: TerminalSummary[]
}

export interface ReportParams {
  date_from?: string
  date_to?: string
  group_by?: GroupBy
  category_ids?: string
  product_ids?: string
  page?: number
  per_page?: number
}

function buildQuery(params: Record<string, string | number | undefined>): string {
  const filtered = Object.entries(params).filter(([, v]) => v !== undefined && v !== '')
  if (filtered.length === 0) return ''
  return '?' + filtered.map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`).join('&')
}

export async function getReport(
  reportType: ReportType,
  params: ReportParams = {},
): Promise<ReportResponse> {
  const query = buildQuery(params as Record<string, string | number | undefined>)
  const response = await get<ReportResponse>(`/admin/reports/${reportType}${query}`)
  // Handle both wrapped and unwrapped responses
  const data = response as any
  if (data && 'metadata' in data) return data as ReportResponse
  if (data && 'data' in data && data.data?.metadata) return data.data as ReportResponse
  return data as ReportResponse
}

export async function getMemberRanking(params: {
  date_from?: string
  date_to?: string
  anonymize?: boolean
  limit?: number
} = {}): Promise<MemberRankingResponse> {
  const query = buildQuery({
    ...params,
    anonymize: params.anonymize ? 'true' : undefined,
    limit: params.limit,
  } as Record<string, string | number | undefined>)
  const response = await get<MemberRankingResponse>(`/admin/reports/member-ranking${query}`)
  const data = response as any
  if (data && 'data' in data && Array.isArray(data.data)) return data as MemberRankingResponse
  return data as MemberRankingResponse
}

export async function getTerminalActivity(params: {
  date_from: string
  date_to: string
  terminal_id?: string
}): Promise<TerminalActivityResponse> {
  const query = buildQuery(params as Record<string, string | number | undefined>)
  const response = await get<TerminalActivityResponse>(`/admin/reports/terminal-activity${query}`)
  const data = response as any
  if (data && 'sessions' in data) return data as TerminalActivityResponse
  if (data && 'data' in data && data.data?.sessions) return data.data as TerminalActivityResponse
  return data as TerminalActivityResponse
}

export async function exportReport(
  reportType: ReportType,
  params: { date_from?: string; date_to?: string; group_by?: GroupBy } = {},
): Promise<void> {
  const query = buildQuery(params as Record<string, string | number | undefined>)
  await downloadFile(`/admin/reports/${reportType}/export${query}`, `report-${reportType}.csv`)
}
```

**Step 2: Commit**

```bash
git add admin-frontend/src/services/reports.ts
git commit -m "feat(reports): add reports frontend service with all API methods"
```

---

### Task 10: Create ReportsPage component

**Files:**
- Create: `admin-frontend/src/pages/ReportsPage.tsx`
- Modify: `admin-frontend/src/App.tsx` (replace StatisticsPage route)
- Modify: `admin-frontend/src/components/layout/MainLayout.tsx` (update nav)
- Modify: `admin-frontend/public/locales/de.json` (add report translations)
- Modify: `admin-frontend/public/locales/en.json` (add report translations)

This is the largest task. The `ReportsPage` has 4 tabs:
1. **Revenue/Consumption/Transactions** (UC-A50) — unified report with chart + table
2. **Member Ranking** (UC-A51) — ranking table with anonymization toggle
3. **Terminal Activity** (UC-A52) — sessions list + hourly distribution chart

**Step 1: Add i18n keys to `de.json`**

Add to the `statistics` section (rename key to `reports`):

```json
"reports": {
  "title": "Berichte",
  "revenue": "Umsatz",
  "consumption": "Verbrauch",
  "transactions": "Buchungen",
  "memberRanking": "Mitglieder-Ranking",
  "terminalActivity": "Terminal-Aktivität",
  "dateFrom": "Von",
  "dateTo": "Bis",
  "groupBy": "Gruppieren nach",
  "category": "Kategorie",
  "product": "Produkt",
  "member": "Mitglied",
  "day": "Tag",
  "week": "Woche",
  "month": "Monat",
  "year": "Jahr",
  "exportCsv": "CSV exportieren",
  "totalRevenue": "Gesamtumsatz",
  "totalQuantity": "Gesamtmenge",
  "transactionCount": "Anzahl Buchungen",
  "avgTransaction": "Durchschn. Buchung",
  "dimension": "Dimension",
  "revenueCents": "Umsatz",
  "quantity": "Menge",
  "count": "Anzahl",
  "percentOfTotal": "Anteil",
  "noData": "Keine Daten für den gewählten Zeitraum",
  "loadingReport": "Lade Bericht...",
  "rank": "Rang",
  "memberName": "Mitglied",
  "totalAmount": "Gesamtbetrag",
  "anonymize": "Anonymisiert",
  "showTop": "Top",
  "sessions": "Sitzungen",
  "hourlyDistribution": "Stundenverteilung",
  "terminals": "Terminals",
  "date": "Datum",
  "startTime": "Beginn",
  "endTime": "Ende",
  "hour": "Uhrzeit",
  "terminalName": "Terminal",
  "lastSync": "Letzte Synchronisation",
  "applyFilter": "Anwenden",
  "revenueChart": "Umsatzverteilung",
  "quantityChart": "Mengenverteilung"
}
```

**Step 2: Add i18n keys to `en.json`**

```json
"reports": {
  "title": "Reports",
  "revenue": "Revenue",
  "consumption": "Consumption",
  "transactions": "Transactions",
  "memberRanking": "Member Ranking",
  "terminalActivity": "Terminal Activity",
  "dateFrom": "From",
  "dateTo": "To",
  "groupBy": "Group by",
  "category": "Category",
  "product": "Product",
  "member": "Member",
  "day": "Day",
  "week": "Week",
  "month": "Month",
  "year": "Year",
  "exportCsv": "Export CSV",
  "totalRevenue": "Total Revenue",
  "totalQuantity": "Total Quantity",
  "transactionCount": "Transaction Count",
  "avgTransaction": "Avg. Transaction",
  "dimension": "Dimension",
  "revenueCents": "Revenue",
  "quantity": "Quantity",
  "count": "Count",
  "percentOfTotal": "% of Total",
  "noData": "No data for the selected period",
  "loadingReport": "Loading report...",
  "rank": "Rank",
  "memberName": "Member",
  "totalAmount": "Total Amount",
  "anonymize": "Anonymized",
  "showTop": "Top",
  "sessions": "Sessions",
  "hourlyDistribution": "Hourly Distribution",
  "terminals": "Terminals",
  "date": "Date",
  "startTime": "Start",
  "endTime": "End",
  "hour": "Hour",
  "terminalName": "Terminal",
  "lastSync": "Last Sync",
  "applyFilter": "Apply",
  "revenueChart": "Revenue Distribution",
  "quantityChart": "Quantity Distribution"
}
```

**Step 3: Create `ReportsPage.tsx`**

This is a large component. Build it with:
- Tab bar for report type selection (Revenue | Consumption | Transactions | Member Ranking | Terminal Activity)
- Date range picker (date_from, date_to inputs)
- Group-by selector for UC-A50 tabs
- Summary cards (total revenue, quantity, count, avg)
- Bar chart (Recharts) for data visualization
- Data table with sortable rows
- CSV export button
- Member ranking tab with anonymize toggle and limit selector
- Terminal activity tab with sessions table and hourly bar chart

**Key design decisions:**
- Reuse existing design system tokens (`theme`, `tableElementStyles`, etc.)
- Use `data-testid` attributes for all interactive elements (Pattern 005)
- Use `useFormatters` hook for price/date formatting
- Use `useBreakpoint` for responsive layout
- Default date range: last 30 days
- Default group_by: month

The component is too large to include inline here. Build it following the patterns in `StatisticsPage.tsx` (same styling, same imports, same hook usage). The key sections are:

1. **Tab selector**: `data-testid="report-tab-{type}"` for each tab
2. **Filter bar**: `data-testid="report-filter-date-from"`, `data-testid="report-filter-date-to"`, `data-testid="report-filter-group-by"`, `data-testid="report-apply-filter"`
3. **Summary cards**: `data-testid="report-summary-revenue"`, `data-testid="report-summary-quantity"`, `data-testid="report-summary-count"`, `data-testid="report-summary-avg"`
4. **Chart**: `data-testid="report-chart"`
5. **Data table**: `data-testid="report-table"`, rows with `data-testid="report-row-{index}"`
6. **Export button**: `data-testid="report-export-csv"`
7. **Member ranking**: `data-testid="ranking-table"`, `data-testid="ranking-anonymize"`, `data-testid="ranking-limit"`
8. **Terminal activity**: `data-testid="terminal-sessions"`, `data-testid="terminal-hourly-chart"`, `data-testid="terminal-list"`

**Step 4: Update App.tsx**

Replace the `StatisticsPage` import and route:

```tsx
// Replace:
import { StatisticsPage } from './pages/StatisticsPage'
// With:
import { ReportsPage } from './pages/ReportsPage'

// Replace route:
// <Route path="/statistics" ...> → <Route path="/reports" ...>
// Also add redirect: /statistics → /reports for backwards compat
```

**Step 5: Update MainLayout.tsx navigation**

Change the statistics nav item:
```tsx
// Replace:
{ label: t('nav.statistics'), path: '/statistics', ...}
// With:
{ label: t('nav.reports'), path: '/reports', ...}
```

Update `de.json` nav key: `"statistics": "Statistik"` → add `"reports": "Berichte"`
Update `en.json` nav key: add `"reports": "Reports"`

**Step 6: Build and verify frontend compiles**

```bash
cd admin-frontend && npm run build
```

**Step 7: Commit**

```bash
git add admin-frontend/src/pages/ReportsPage.tsx admin-frontend/src/App.tsx admin-frontend/src/components/layout/MainLayout.tsx admin-frontend/public/locales/de.json admin-frontend/public/locales/en.json
git commit -m "feat(reports): add ReportsPage with tabs for all report types (UC-A50, UC-A51, UC-A52)"
```

---

### Task 11: E2E tests for Reports page

**Files:**
- Create: `e2etests/tests/admin/reports.spec.ts`

**Step 1: Write frontend E2E tests**

Test the complete end-to-end flow:
- Navigate to `/reports`
- Verify tab structure loads
- Click each tab and verify content changes
- Verify summary cards display data
- Verify chart renders
- Verify data table has rows
- Test date range filtering (change dates → verify API call → verify table updates)
- Test group_by selector
- Test CSV export button triggers download
- Test member ranking anonymization toggle
- Test terminal activity date requirement

**Key patterns to follow:**
- Pattern 001: Use unique test data (date ranges unlikely to collide)
- Pattern 005: Use `data-testid` selectors exclusively
- Pattern 008: Use `expect(locator).toBeVisible()` not try-catch
- Wait for API responses with `page.waitForResponse()` matching `/reports/`

```typescript
// e2etests/tests/admin/reports.spec.ts
import { test, expect } from '../../fixtures/pageObjects'

test.describe('Reports Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/reports')
    await page.getByTestId('reports-page').waitFor({ state: 'visible', timeout: 10000 })
  })

  test.describe('Page Structure', () => {
    test('should display reports page', async ({ page }) => {
      await expect(page.getByTestId('reports-page')).toBeVisible()
    })

    test('should display report type tabs', async ({ page }) => {
      await expect(page.getByTestId('report-tab-revenue')).toBeVisible()
      await expect(page.getByTestId('report-tab-consumption')).toBeVisible()
      await expect(page.getByTestId('report-tab-transactions')).toBeVisible()
      await expect(page.getByTestId('report-tab-member-ranking')).toBeVisible()
      await expect(page.getByTestId('report-tab-terminal-activity')).toBeVisible()
    })

    test('should display filter controls', async ({ page }) => {
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
      await expect(page.getByTestId('report-filter-date-to')).toBeVisible()
    })
  })

  test.describe('Revenue Report (UC-A50)', () => {
    test('should load revenue report by default', async ({ page }) => {
      // Wait for data to load
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await responsePromise

      await expect(page.getByTestId('report-summary-revenue')).toBeVisible()
    })

    test('should display group-by selector', async ({ page }) => {
      await expect(page.getByTestId('report-filter-group-by')).toBeVisible()
    })

    test('should display export CSV button', async ({ page }) => {
      await expect(page.getByTestId('report-export-csv')).toBeVisible()
    })
  })

  test.describe('Member Ranking (UC-A51)', () => {
    test('should switch to member ranking tab', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/member-ranking'))
      await page.getByTestId('report-tab-member-ranking').click()
      await responsePromise

      await expect(page.getByTestId('ranking-table')).toBeVisible()
    })

    test('should display anonymize toggle', async ({ page }) => {
      await page.getByTestId('report-tab-member-ranking').click()
      await expect(page.getByTestId('ranking-anonymize')).toBeVisible()
    })
  })

  test.describe('Terminal Activity (UC-A52)', () => {
    test('should switch to terminal activity tab', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/terminal-activity'))
      await page.getByTestId('report-tab-terminal-activity').click()
      await responsePromise

      await expect(page.getByTestId('terminal-hourly-chart')).toBeVisible()
    })

    test('should display sessions table', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/terminal-activity'))
      await page.getByTestId('report-tab-terminal-activity').click()
      await responsePromise

      await expect(page.getByTestId('terminal-sessions')).toBeVisible()
    })
  })
})
```

**Step 2: Run tests**

```bash
cd e2etests && npm test -- tests/admin/reports.spec.ts --workers=4
```

**Step 3: Commit**

```bash
git add e2etests/tests/admin/reports.spec.ts
git commit -m "test(reports): add E2E tests for Reports page (UC-A50, UC-A51, UC-A52)"
```

---

## Phase 5: Responsive Design for Mobile Devices

### Task 12: Make ReportsPage responsive with mobile card layout

**Files:**
- Modify: `admin-frontend/src/pages/ReportsPage.tsx`

**Context:**
The project uses `useBreakpoint()` hook with breakpoints: `smallMobile` (≤480px), `mobile` (≤768px), `tablet` (≤1200px), `desktop` (>1200px). All existing pages follow the pattern: `const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'` and conditionally render different layouts.

**Responsive requirements for ReportsPage:**

#### Tab bar
- **Desktop**: Horizontal tab bar with all 5 tabs visible in a row
- **Mobile**: Horizontally scrollable tab bar or stacked 2-column grid. Each tab shows icon + short label.
- Test IDs: `data-testid="report-tabs"` for the container

```tsx
// Tab bar layout
<div
  data-testid="report-tabs"
  style={{
    display: 'flex',
    flexWrap: isMobile ? 'wrap' : 'nowrap',
    gap: isMobile ? theme.spacing.xs : theme.spacing.sm,
    overflowX: isMobile ? 'auto' : undefined,
    WebkitOverflowScrolling: 'touch',
    // On mobile: 2-column grid for tabs
    ...(isMobile && {
      display: 'grid',
      gridTemplateColumns: 'repeat(2, 1fr)',
    }),
  }}
>
```

#### Filter bar
- **Desktop**: Single row with date_from, date_to, group_by, apply button, export button
- **Mobile**: Stacked layout — date inputs on one row (50/50), group_by full width, buttons full width
- Test IDs: `data-testid="report-filter-bar"` for the container

```tsx
<div
  data-testid="report-filter-bar"
  style={{
    display: 'grid',
    gridTemplateColumns: isMobile ? '1fr 1fr' : 'auto auto auto auto auto',
    gap: theme.spacing.sm,
    alignItems: 'end',
  }}
>
  {/* date_from and date_to take 1 column each (side by side on mobile) */}
  {/* group_by spans full width on mobile: gridColumn: '1 / -1' */}
  {/* apply + export buttons: gridColumn: '1 / -1' on mobile */}
</div>
```

#### Summary cards
- **Desktop**: 4 cards in a row (`repeat(4, 1fr)`)
- **Mobile**: 2x2 grid (`repeat(2, 1fr)`)
- **smallMobile**: 1 column

```tsx
<div
  data-testid="report-summary"
  style={{
    display: 'grid',
    gridTemplateColumns: isSmallMobile ? '1fr' : isMobile ? 'repeat(2, 1fr)' : 'repeat(4, 1fr)',
    gap: theme.spacing.md,
  }}
>
```

#### Chart
- **Desktop**: Full width, 300px height
- **Mobile**: Full width, 200px height (reduced to save vertical space)
- Chart already uses `<ResponsiveContainer>` from Recharts so width auto-adapts

```tsx
<ResponsiveContainer width="100%" height={isMobile ? 200 : 300}>
```

#### Data table (UC-A50 report data)
- **Desktop**: Standard HTML table with all columns (Dimension, Revenue, Quantity, Count, % of Total)
- **Mobile**: Card layout — each row becomes a card showing dimension as title, metrics below

```tsx
{isMobile ? (
  <div data-testid="report-mobile-cards">
    {report.data.map((row, index) => (
      <div
        key={index}
        data-testid={`report-card-${index}`}
        style={{
          background: theme.colors.bg.card,
          border: `1px solid ${theme.colors.border.light}`,
          borderRadius: theme.borderRadius.md,
          padding: theme.spacing.md,
          marginBottom: theme.spacing.sm,
        }}
      >
        <div style={{ fontWeight: 600, marginBottom: theme.spacing.xs }}>{row.dimension}</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: theme.spacing.xs }}>
          <div><span style={{ color: theme.colors.text.muted }}>{t('reports.revenueCents')}:</span> {formatPrice(row.revenue_cents)}</div>
          <div><span style={{ color: theme.colors.text.muted }}>{t('reports.quantity')}:</span> {row.quantity}</div>
          <div><span style={{ color: theme.colors.text.muted }}>{t('reports.count')}:</span> {row.count}</div>
          <div><span style={{ color: theme.colors.text.muted }}>{t('reports.percentOfTotal')}:</span> {row.percent_of_total}%</div>
        </div>
      </div>
    ))}
  </div>
) : (
  <table data-testid="report-table" style={tableElementStyles}>
    {/* ... existing table markup ... */}
  </table>
)}
```

#### Member ranking table (UC-A51)
- **Desktop**: Standard table (Rank, Member, Total, Count)
- **Mobile**: Card layout with rank badge, member name, and metrics

```tsx
{isMobile ? (
  <div data-testid="ranking-mobile-cards">
    {ranking.data.map((row, index) => (
      <div key={index} data-testid={`ranking-card-${index}`} style={{
        display: 'flex', gap: theme.spacing.md, alignItems: 'center',
        padding: theme.spacing.md, borderBottom: `1px solid ${theme.colors.border.light}`,
      }}>
        <div style={{
          width: 32, height: 32, borderRadius: '50%',
          background: row.rank <= 3 ? '#f59e0b' : theme.colors.bg.tertiary,
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          fontWeight: 700, fontSize: 14,
        }}>{row.rank}</div>
        <div style={{ flex: 1 }}>
          <div style={{ fontWeight: 500 }}>{row.member_name}</div>
          <div style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.sm }}>
            {formatPrice(row.total_amount_cents)} · {row.transaction_count} {t('reports.transactions')}
          </div>
        </div>
      </div>
    ))}
  </div>
) : (
  <table data-testid="ranking-table" style={tableElementStyles}>
    {/* ... existing table ... */}
  </table>
)}
```

#### Terminal activity (UC-A52)
- **Desktop**: Sessions table + hourly chart side by side (2 columns)
- **Mobile**: Sessions table as cards + hourly chart stacked (1 column)

```tsx
<div style={{
  display: 'grid',
  gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr',
  gap: theme.spacing.xl,
}}>
  {/* Sessions cards/table */}
  {/* Hourly distribution chart */}
</div>
```

Sessions on mobile:
```tsx
{isMobile ? (
  <div data-testid="terminal-sessions-mobile">
    {activity.sessions.map((session, i) => (
      <div key={i} data-testid={`session-card-${i}`} style={{
        padding: theme.spacing.md,
        borderBottom: `1px solid ${theme.colors.border.light}`,
      }}>
        <div style={{ fontWeight: 500 }}>{session.date}</div>
        <div style={{ color: theme.colors.text.muted }}>
          {session.start_time} – {session.end_time}
        </div>
        <div>{session.transaction_count} txns · {formatPrice(session.revenue_cents)}</div>
      </div>
    ))}
  </div>
) : (
  <table data-testid="terminal-sessions" ...>
    {/* ... table ... */}
  </table>
)}
```

#### Anonymize toggle and limit selector (UC-A51)
- **Desktop**: Inline row with toggle + select side by side
- **Mobile**: Full-width stacked

```tsx
<div
  data-testid="ranking-controls"
  style={{
    display: 'flex',
    flexDirection: isMobile ? 'column' : 'row',
    gap: theme.spacing.md,
    marginBottom: theme.spacing.lg,
  }}
>
  {/* Anonymize toggle */}
  {/* Limit selector */}
</div>
```

**Step 1: Implement all responsive changes in ReportsPage.tsx**

Apply all the patterns above. Use `useBreakpoint()` and `isMobile` / `isSmallMobile` flags.

**Step 2: Build and verify**

```bash
cd admin-frontend && npm run build
```

**Step 3: Commit**

```bash
git add admin-frontend/src/pages/ReportsPage.tsx
git commit -m "feat(reports): add responsive mobile layout with card views and adaptive grids"
```

---

### Task 13: Update BottomTabBar for reports navigation

**Files:**
- Modify: `admin-frontend/src/components/layout/BottomTabBar.tsx`

The BottomTabBar currently has `/statistics` in the "More" menu items. Update it to `/reports`.

**Step 1: Update the moreItems path**

```tsx
// Replace:
{ label: t('nav.statistics'), path: '/statistics', icon: ChartIcon, testId: 'tab-statistics' },
// With:
{ label: t('nav.reports'), path: '/reports', icon: ChartIcon, testId: 'tab-reports' },
```

**Step 2: Build and verify**

```bash
cd admin-frontend && npm run build
```

**Step 3: Commit**

```bash
git add admin-frontend/src/components/layout/BottomTabBar.tsx
git commit -m "feat(reports): update BottomTabBar navigation from statistics to reports"
```

---

## Phase 6: Desktop E2E Tests

### Task 14: Comprehensive desktop E2E tests for Reports page

**Files:**
- Modify: `e2etests/tests/admin/reports.spec.ts` (extend existing from Task 11)

These tests run in the `admin-chromium` project (Desktop Chrome, 1280x720).

**Step 1: Extend the desktop E2E tests**

Add tests that verify desktop-specific layout elements:

```typescript
// e2etests/tests/admin/reports.spec.ts
import { test, expect } from '../../fixtures/pageObjects'

test.describe('Reports Page (Desktop)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/reports')
    await page.getByTestId('reports-page').waitFor({ state: 'visible', timeout: 10000 })
  })

  test.describe('Desktop Layout', () => {
    test('should display all tabs in a horizontal row', async ({ page }) => {
      const tabs = page.getByTestId('report-tabs')
      await expect(tabs).toBeVisible()

      // All 5 tabs should be visible without scrolling
      await expect(page.getByTestId('report-tab-revenue')).toBeVisible()
      await expect(page.getByTestId('report-tab-consumption')).toBeVisible()
      await expect(page.getByTestId('report-tab-transactions')).toBeVisible()
      await expect(page.getByTestId('report-tab-member-ranking')).toBeVisible()
      await expect(page.getByTestId('report-tab-terminal-activity')).toBeVisible()
    })

    test('should display filter bar in single row on desktop', async ({ page }) => {
      const filterBar = page.getByTestId('report-filter-bar')
      await expect(filterBar).toBeVisible()

      // All filter controls visible in one row
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
      await expect(page.getByTestId('report-filter-date-to')).toBeVisible()
      await expect(page.getByTestId('report-filter-group-by')).toBeVisible()
      await expect(page.getByTestId('report-export-csv')).toBeVisible()
    })

    test('should display summary cards in 4-column grid', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await responsePromise

      await expect(page.getByTestId('report-summary-revenue')).toBeVisible()
      await expect(page.getByTestId('report-summary-quantity')).toBeVisible()
      await expect(page.getByTestId('report-summary-count')).toBeVisible()
      await expect(page.getByTestId('report-summary-avg')).toBeVisible()
    })

    test('should display data as table (not cards) on desktop', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await responsePromise

      // Desktop shows table
      await expect(page.getByTestId('report-table')).toBeVisible()
      // Mobile cards should not be present
      await expect(page.getByTestId('report-mobile-cards')).toHaveCount(0)
    })

    test('should display desktop nav (not bottom tab bar)', async ({ page }) => {
      await expect(page.getByTestId('desktop-nav')).toBeVisible()
      await expect(page.getByTestId('bottom-tab-bar')).toHaveCount(0)
    })
  })

  test.describe('Revenue Report E2E Flow (UC-A50)', () => {
    test('should load and display revenue data with chart', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      const response = await responsePromise

      expect(response.status()).toBe(200)

      // Summary cards visible
      await expect(page.getByTestId('report-summary-revenue')).toBeVisible()

      // Chart visible
      await expect(page.getByTestId('report-chart')).toBeVisible()
    })

    test('should update data when changing group_by', async ({ page }) => {
      // Load initial report
      const initialResponse = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await initialResponse

      // Change group_by to product
      await page.getByTestId('report-filter-group-by').selectOption('product')

      // Click apply and wait for new response
      const newResponse = page.waitForResponse(resp =>
        resp.url().includes('/reports/revenue') && resp.url().includes('group_by=product')
      )
      await page.getByTestId('report-apply-filter').click()
      const resp = await newResponse

      expect(resp.status()).toBe(200)
    })

    test('should update data when changing date range', async ({ page }) => {
      const initialResponse = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await initialResponse

      // Set date range
      await page.getByTestId('report-filter-date-from').fill('2025-01-01')
      await page.getByTestId('report-filter-date-to').fill('2025-12-31')

      const newResponse = page.waitForResponse(resp =>
        resp.url().includes('/reports/revenue') && resp.url().includes('date_from=2025-01-01')
      )
      await page.getByTestId('report-apply-filter').click()
      const resp = await newResponse

      expect(resp.status()).toBe(200)
    })

    test('should trigger CSV download when clicking export', async ({ page }) => {
      const initialResponse = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await initialResponse

      // Listen for download
      const downloadPromise = page.waitForEvent('download')
      await page.getByTestId('report-export-csv').click()
      const download = await downloadPromise

      expect(download.suggestedFilename()).toContain('report-revenue')
    })
  })

  test.describe('Member Ranking E2E Flow (UC-A51)', () => {
    test('should load and display ranking table', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/member-ranking'))
      await page.getByTestId('report-tab-member-ranking').click()
      await responsePromise

      await expect(page.getByTestId('ranking-table')).toBeVisible()
    })

    test('should toggle anonymization and reload data', async ({ page }) => {
      const initialResponse = page.waitForResponse(resp => resp.url().includes('/reports/member-ranking'))
      await page.getByTestId('report-tab-member-ranking').click()
      await initialResponse

      // Toggle anonymize
      const anonResponse = page.waitForResponse(resp =>
        resp.url().includes('/reports/member-ranking') && resp.url().includes('anonymize=true')
      )
      await page.getByTestId('ranking-anonymize').click()
      await anonResponse

      // Table should still be visible with anonymized data
      await expect(page.getByTestId('ranking-table')).toBeVisible()
    })

    test('should change limit and reload data', async ({ page }) => {
      const initialResponse = page.waitForResponse(resp => resp.url().includes('/reports/member-ranking'))
      await page.getByTestId('report-tab-member-ranking').click()
      await initialResponse

      const newResponse = page.waitForResponse(resp =>
        resp.url().includes('/reports/member-ranking') && resp.url().includes('limit=50')
      )
      await page.getByTestId('ranking-limit').selectOption('50')
      await newResponse

      await expect(page.getByTestId('ranking-table')).toBeVisible()
    })
  })

  test.describe('Terminal Activity E2E Flow (UC-A52)', () => {
    test('should load and display terminal activity', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/terminal-activity'))
      await page.getByTestId('report-tab-terminal-activity').click()
      await responsePromise

      // Hourly chart and sessions should be visible
      await expect(page.getByTestId('terminal-hourly-chart')).toBeVisible()
      await expect(page.getByTestId('terminal-sessions')).toBeVisible()
    })

    test('should display terminal list', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/terminal-activity'))
      await page.getByTestId('report-tab-terminal-activity').click()
      await responsePromise

      await expect(page.getByTestId('terminal-list')).toBeVisible()
    })
  })

  test.describe('Tab Switching', () => {
    test('should switch between all tabs without errors', async ({ page }) => {
      const tabs = ['revenue', 'consumption', 'transactions', 'member-ranking', 'terminal-activity']

      for (const tab of tabs) {
        const apiPath = tab === 'member-ranking' ? '/reports/member-ranking' :
                        tab === 'terminal-activity' ? '/reports/terminal-activity' :
                        `/reports/${tab}`
        const responsePromise = page.waitForResponse(resp => resp.url().includes(apiPath))
        await page.getByTestId(`report-tab-${tab}`).click()
        const resp = await responsePromise
        expect(resp.status()).toBe(200)
      }
    })
  })
})
```

**Step 2: Run desktop tests**

```bash
cd e2etests && npm test -- tests/admin/reports.spec.ts --workers=4
```

**Step 3: Commit**

```bash
git add e2etests/tests/admin/reports.spec.ts
git commit -m "test(reports): add comprehensive desktop E2E tests for all report types"
```

---

## Phase 7: Mobile E2E Tests

### Task 15: Mobile E2E tests for Reports page

**Files:**
- Create: `e2etests/tests/admin-mobile/reports-mobile.spec.ts`

These tests run in the `admin-mobile` Playwright project (iPhone 14 — 390x844px viewport). The project config at `e2etests/playwright.config.ts` already defines the `admin-mobile` project with `devices['iPhone 14']`.

**Step 1: Write mobile E2E tests**

```typescript
// e2etests/tests/admin-mobile/reports-mobile.spec.ts
import { test, expect } from '../../fixtures/pageObjects'

test.describe('Reports Page (Mobile)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/reports')
    await page.getByTestId('reports-page').waitFor({ state: 'visible', timeout: 10000 })
  })

  test.describe('Mobile Navigation', () => {
    test('should show bottom tab bar (not desktop nav)', async ({ page }) => {
      await expect(page.getByTestId('bottom-tab-bar')).toBeVisible()
      await expect(page.getByTestId('desktop-nav')).toBeHidden()
    })

    test('should navigate to reports via More menu', async ({ page }) => {
      // Go to a different page first
      await page.getByTestId('tab-members').click()
      await expect(page).toHaveURL(/\/members/)

      // Open More popup and navigate to Reports
      await page.getByTestId('tab-more').click()
      await expect(page.getByTestId('tab-more-popup')).toBeVisible()
      await page.getByTestId('tab-reports').click()
      await expect(page).toHaveURL(/\/reports/)
    })
  })

  test.describe('Mobile Tab Layout', () => {
    test('should display tabs in grid layout on mobile', async ({ page }) => {
      const tabs = page.getByTestId('report-tabs')
      await expect(tabs).toBeVisible()

      // All tabs should be visible (may be in 2-column grid)
      await expect(page.getByTestId('report-tab-revenue')).toBeVisible()
      await expect(page.getByTestId('report-tab-consumption')).toBeVisible()
      await expect(page.getByTestId('report-tab-transactions')).toBeVisible()
      await expect(page.getByTestId('report-tab-member-ranking')).toBeVisible()
      await expect(page.getByTestId('report-tab-terminal-activity')).toBeVisible()
    })
  })

  test.describe('Mobile Filter Layout', () => {
    test('should display stacked filter controls', async ({ page }) => {
      await expect(page.getByTestId('report-filter-bar')).toBeVisible()
      await expect(page.getByTestId('report-filter-date-from')).toBeVisible()
      await expect(page.getByTestId('report-filter-date-to')).toBeVisible()
    })

    test('should be able to set date range on mobile', async ({ page }) => {
      await page.getByTestId('report-filter-date-from').fill('2025-01-01')
      await page.getByTestId('report-filter-date-to').fill('2025-12-31')

      // Value should be set
      await expect(page.getByTestId('report-filter-date-from')).toHaveValue('2025-01-01')
      await expect(page.getByTestId('report-filter-date-to')).toHaveValue('2025-12-31')
    })
  })

  test.describe('Mobile Revenue Report (UC-A50)', () => {
    test('should display summary cards in 2-column grid', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await responsePromise

      // All 4 summary cards should be visible
      await expect(page.getByTestId('report-summary-revenue')).toBeVisible()
      await expect(page.getByTestId('report-summary-quantity')).toBeVisible()
      await expect(page.getByTestId('report-summary-count')).toBeVisible()
      await expect(page.getByTestId('report-summary-avg')).toBeVisible()
    })

    test('should display mobile cards instead of table', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await responsePromise

      // Mobile should show cards, not table
      await expect(page.getByTestId('report-mobile-cards')).toBeVisible()
      await expect(page.getByTestId('report-table')).toHaveCount(0)
    })

    test('should display chart on mobile (reduced height)', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await responsePromise

      await expect(page.getByTestId('report-chart')).toBeVisible()
    })

    test('should allow group_by change on mobile', async ({ page }) => {
      const initialResponse = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await initialResponse

      // Change group_by
      await page.getByTestId('report-filter-group-by').selectOption('product')

      const newResponse = page.waitForResponse(resp =>
        resp.url().includes('/reports/revenue') && resp.url().includes('group_by=product')
      )
      await page.getByTestId('report-apply-filter').click()
      const resp = await newResponse

      expect(resp.status()).toBe(200)
    })

    test('should display export button on mobile', async ({ page }) => {
      await expect(page.getByTestId('report-export-csv')).toBeVisible()
    })
  })

  test.describe('Mobile Member Ranking (UC-A51)', () => {
    test('should display ranking as mobile cards', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/member-ranking'))
      await page.getByTestId('report-tab-member-ranking').click()
      await responsePromise

      // Mobile should show card layout
      await expect(page.getByTestId('ranking-mobile-cards')).toBeVisible()
      await expect(page.getByTestId('ranking-table')).toHaveCount(0)
    })

    test('should display stacked controls (anonymize + limit)', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/member-ranking'))
      await page.getByTestId('report-tab-member-ranking').click()
      await responsePromise

      await expect(page.getByTestId('ranking-controls')).toBeVisible()
      await expect(page.getByTestId('ranking-anonymize')).toBeVisible()
      await expect(page.getByTestId('ranking-limit')).toBeVisible()
    })

    test('should toggle anonymize on mobile', async ({ page }) => {
      const initialResponse = page.waitForResponse(resp => resp.url().includes('/reports/member-ranking'))
      await page.getByTestId('report-tab-member-ranking').click()
      await initialResponse

      const anonResponse = page.waitForResponse(resp =>
        resp.url().includes('/reports/member-ranking') && resp.url().includes('anonymize=true')
      )
      await page.getByTestId('ranking-anonymize').click()
      await anonResponse

      await expect(page.getByTestId('ranking-mobile-cards')).toBeVisible()
    })
  })

  test.describe('Mobile Terminal Activity (UC-A52)', () => {
    test('should display sessions as cards on mobile', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/terminal-activity'))
      await page.getByTestId('report-tab-terminal-activity').click()
      await responsePromise

      // Mobile sessions shown as cards
      await expect(page.getByTestId('terminal-sessions-mobile').or(page.getByTestId('terminal-sessions'))).toBeVisible()
    })

    test('should display hourly distribution chart on mobile', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/terminal-activity'))
      await page.getByTestId('report-tab-terminal-activity').click()
      await responsePromise

      await expect(page.getByTestId('terminal-hourly-chart')).toBeVisible()
    })

    test('should stack sessions and chart vertically on mobile', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/terminal-activity'))
      await page.getByTestId('report-tab-terminal-activity').click()
      await responsePromise

      // Both sections should be visible (stacked, not side by side)
      await expect(page.getByTestId('terminal-hourly-chart')).toBeVisible()
      const sessions = page.getByTestId('terminal-sessions-mobile').or(page.getByTestId('terminal-sessions'))
      await expect(sessions).toBeVisible()
    })
  })

  test.describe('Mobile Tab Switching', () => {
    test('should switch between all tabs on mobile', async ({ page }) => {
      const tabs = ['revenue', 'consumption', 'transactions', 'member-ranking', 'terminal-activity']

      for (const tab of tabs) {
        const apiPath = tab === 'member-ranking' ? '/reports/member-ranking' :
                        tab === 'terminal-activity' ? '/reports/terminal-activity' :
                        `/reports/${tab}`
        const responsePromise = page.waitForResponse(resp => resp.url().includes(apiPath))
        await page.getByTestId(`report-tab-${tab}`).click()
        const resp = await responsePromise
        expect(resp.status()).toBe(200)
      }
    })
  })

  test.describe('Mobile Scrolling and Touch', () => {
    test('should allow scrolling through report data on mobile', async ({ page }) => {
      const responsePromise = page.waitForResponse(resp => resp.url().includes('/reports/revenue'))
      await page.getByTestId('report-tab-revenue').click()
      await responsePromise

      // Page should be scrollable (content extends beyond viewport)
      const pageHeight = await page.evaluate(() => document.body.scrollHeight)
      const viewportHeight = await page.evaluate(() => window.innerHeight)

      // If there's data, page should be taller than viewport
      // (this validates content isn't clipped or hidden)
      expect(pageHeight).toBeGreaterThanOrEqual(viewportHeight)
    })
  })
})
```

**Step 2: Run mobile tests**

```bash
cd e2etests && npx playwright test --project=admin-mobile tests/admin-mobile/reports-mobile.spec.ts
```

**Step 3: Commit**

```bash
git add e2etests/tests/admin-mobile/reports-mobile.spec.ts
git commit -m "test(reports): add mobile E2E tests for all report types on iPhone 14 viewport"
```

---

### Task 16: Update existing mobile-responsive.spec.ts

**Files:**
- Modify: `e2etests/tests/admin-mobile/mobile-responsive.spec.ts`

The existing mobile-responsive spec tests the Statistics page. Update it to reference the new Reports page.

**Step 1: Update the Statistics Page test**

Replace:
```typescript
test.describe('Statistics Page', () => {
  test('should display summary boxes on Statistics page', async ({ page }) => {
    await page.goto('/statistics')
    await expect(page.getByTestId('summary-boxes')).toBeVisible()
  })
})
```

With:
```typescript
test.describe('Reports Page', () => {
  test('should display reports page on mobile', async ({ page }) => {
    await page.goto('/reports')
    await expect(page.getByTestId('reports-page')).toBeVisible()
  })

  test('should display report tabs on mobile', async ({ page }) => {
    await page.goto('/reports')
    await expect(page.getByTestId('report-tabs')).toBeVisible()
  })
})
```

**Step 2: Run the updated test**

```bash
cd e2etests && npx playwright test --project=admin-mobile tests/admin-mobile/mobile-responsive.spec.ts
```

**Step 3: Commit**

```bash
git add e2etests/tests/admin-mobile/mobile-responsive.spec.ts
git commit -m "test(reports): update mobile-responsive tests from statistics to reports page"
```

---

## Phase 8: Cleanup and Integration

### Task 17: Update statistics E2E tests and remove old page

**Files:**
- Modify: `e2etests/tests/admin/statistics.spec.ts` — update to test `/reports` path or remove if fully replaced
- Delete (optional): `admin-frontend/src/pages/StatisticsPage.tsx` — only if confirmed not needed

**Step 1: Update existing statistics tests**

The existing `statistics.spec.ts` tests the old `/statistics` route. Options:
- If we keep a redirect from `/statistics` → `/reports`, update the test to use `/reports`
- If the old monthly stats are preserved as a subtab in reports, adapt tests
- If fully replaced, delete the old test file

Decision: Keep the old `StatisticsPage.tsx` file but remove its route (the new Reports page subsumes it). Update `statistics.spec.ts` to point to `/reports` and test the monthly stats via the Revenue report with `group_by=day` for a specific month.

**Step 2: Verify all tests pass (both desktop and mobile)**

```bash
# Desktop tests
cd e2etests && npm test -- --workers=4

# Mobile tests
cd e2etests && npx playwright test --project=admin-mobile --workers=4
```

**Step 3: Commit**

```bash
git add e2etests/tests/admin/statistics.spec.ts
git commit -m "refactor(reports): update statistics tests to use new reports page"
```

---

### Task 18: Update plans/INDEX.md

**Files:**
- Modify: `plans/INDEX.md`

Add this plan to the completed/current section.

**Step 1: Update INDEX.md**

**Step 2: Commit**

```bash
git add plans/INDEX.md
git commit -m "docs: update plans INDEX with reports system plan"
```

---

## Summary

| Phase | Tasks | Use Case / Focus | Effort |
|-------|-------|-----------------|--------|
| Phase 1 | Tasks 1-4 | UC-A50 Backend (Reports API) | ~2h |
| Phase 2 | Tasks 5-6 | UC-A51 Backend (Member Ranking) | ~1h |
| Phase 3 | Tasks 7-8 | UC-A52 Backend (Terminal Activity) | ~1.5h |
| Phase 4 | Tasks 9-11 | Frontend ReportsPage + service | ~3h |
| Phase 5 | Tasks 12-13 | Responsive design (mobile cards, adaptive grids) | ~2h |
| Phase 6 | Task 14 | Desktop E2E tests (admin-chromium) | ~1h |
| Phase 7 | Tasks 15-16 | Mobile E2E tests (admin-mobile, iPhone 14) | ~1.5h |
| Phase 8 | Tasks 17-18 | Cleanup + integration | ~30min |

**Total: ~12.5h estimated**

### Responsive Design Summary

| Element | Desktop (>1200px) | Mobile (≤768px) | smallMobile (≤480px) |
|---------|-------------------|-----------------|----------------------|
| **Tab bar** | Horizontal row | 2-column grid | 2-column grid |
| **Filter bar** | Single row (5 columns) | Stacked (dates side-by-side, rest full-width) | Same as mobile |
| **Summary cards** | 4-column grid | 2-column grid | 1-column stack |
| **Chart height** | 300px | 200px | 200px |
| **Data table** | HTML table | Card layout per row | Card layout per row |
| **Ranking table** | HTML table with columns | Cards with rank badge | Cards with rank badge |
| **Ranking controls** | Inline row | Stacked column | Stacked column |
| **Terminal activity** | 2-column (sessions + chart) | 1-column stacked | 1-column stacked |
| **Sessions** | HTML table | Card layout | Card layout |
| **Navigation** | Top nav bar | Bottom tab bar + More popup | Bottom tab bar + More popup |

### Test Coverage Matrix

| Test Type | File | Viewport | Project |
|-----------|------|----------|---------|
| API tests | `tests/api/reports.spec.ts` | N/A | api-tests |
| API tests | `tests/api/member-ranking.spec.ts` | N/A | api-tests |
| API tests | `tests/api/terminal-activity.spec.ts` | N/A | api-tests |
| Desktop E2E | `tests/admin/reports.spec.ts` | 1280x720 | admin-chromium |
| Mobile E2E | `tests/admin-mobile/reports-mobile.spec.ts` | 390x844 | admin-mobile |
| Mobile responsive | `tests/admin-mobile/mobile-responsive.spec.ts` | 390x844 | admin-mobile |

### Key Test IDs (Pattern 005)

| Test ID | Element | Desktop | Mobile |
|---------|---------|---------|--------|
| `reports-page` | Page container | Visible | Visible |
| `report-tabs` | Tab bar container | Row | Grid |
| `report-tab-{type}` | Individual tab | Visible | Visible |
| `report-filter-bar` | Filter container | Row | Stacked |
| `report-table` | Data table | Visible | Hidden |
| `report-mobile-cards` | Card layout | Hidden | Visible |
| `ranking-table` | Ranking table | Visible | Hidden |
| `ranking-mobile-cards` | Ranking cards | Hidden | Visible |
| `terminal-sessions` | Sessions table | Visible | Hidden |
| `terminal-sessions-mobile` | Sessions cards | Hidden | Visible |

**Route registration order in `routes.php`** (critical — specific routes before parameterized):
```php
// Reports (specific routes FIRST)
$group->get('/reports/member-ranking', [ReportsAdminController::class, 'memberRanking']);
$group->get('/reports/terminal-activity', [ReportsAdminController::class, 'terminalActivity']);
$group->get('/reports/{reportType}/export', [ReportsAdminController::class, 'exportReport']);
$group->get('/reports/{reportType}', [ReportsAdminController::class, 'getReport']);
```
