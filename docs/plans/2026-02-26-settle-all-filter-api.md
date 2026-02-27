# Settle-All Filter-Based API Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the broken "Abrechnung (alle)" pagination workaround with two new backend APIs: one that returns a lightweight preview (count/members/amount) for transactions matching current journal filters, and one that creates a settlement from those same filters server-side.

**Architecture:** The frontend never fetches or stores individual transaction IDs for "settle-all". Instead it sends filter params to `GET /admin/settlements/filter-preview` to populate the confirm modal, then on confirm sends the same filters to `POST /admin/settlements/settle-filter`. The backend resolves matching transaction IDs at settlement time. The existing `POST /admin/settlements` (ID-list-based) remains unchanged for the "settle selected" flow.

**Tech Stack:** PHP 8.3 (backend), React + TypeScript (frontend), Playwright (E2E tests)

---

## Task 0: Revert bad temporary changes in JournalPage

**Files:**
- Modify: `admin-frontend/src/pages/JournalPage.tsx`

**Step 1: Remove `settleAllLoading` state and revert `handleSettleAll` to original**

Find and remove:
- `const [settleAllLoading, setSettleAllLoading] = useState(false)` (line ~96)
- Replace the new async `handleSettleAll` back to original:

```typescript
const handleSettleAll = () => {
  const openTransactions = state.transactions.filter((tx) => !tx.is_settled)
  if (openTransactions.length === 0) {
    setState((prev) => ({ ...prev, error: t('journal.settlementNoOpen') }))
    return
  }
  setPendingTransactions(openTransactions)
  setConfirmError(null)
  setConfirmModalOpen(true)
}
```

- Remove `settleAllLoading` references from the button JSX, restoring original button markup

**Step 2: Verify TypeScript compiles**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | head -20
```
Expected: no errors

**Step 3: Commit**

```bash
git add admin-frontend/src/pages/JournalPage.tsx
git commit -m "revert: undo temporary settle-all workaround from JournalPage"
```

---

## Task 1: Backend – `summarizeUnsettledByFilters()` in TransactionsRepository

**Files:**
- Modify: `backend/src/Modules/Transactions/Repositories/TransactionsRepository.php`

**Step 1: Add method after `sumUnsettledAmountCents()` (line 189)**

```php
/**
 * Return aggregate stats for transactions matching the given filters,
 * restricted to only unsettled transactions.
 * Accepted filter keys: date_from, date_to, search, member_id
 *
 * @return array{ transaction_count: int, member_count: int, total_amount_cents: int }
 */
public function summarizeUnsettledByFilters(array $filters = []): array
{
    $where = [
        'NOT EXISTS (SELECT 1 FROM settlement_items si JOIN settlements s ON si.settlement_id = s.id WHERE si.transaction_id = t.id AND s.is_cancelled = 0)',
    ];
    $params = [];

    if (isset($filters['date_from'])) {
        $where[] = 't.created_at >= ?';
        $params[] = $filters['date_from'];
    }
    if (isset($filters['date_to'])) {
        $where[] = 't.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (isset($filters['search'])) {
        $escaped = SafeQuery::escapeLike($filters['search']);
        $where[] = "(CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR t.notes LIKE ? OR p.names LIKE ?)";
        $params = array_merge($params, ["%{$escaped}%", "%{$escaped}%", "%{$escaped}%"]);
    }
    if (isset($filters['member_id'])) {
        $where[] = 't.member_id = ?';
        $params[] = $filters['member_id'];
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $stmt = $this->db->prepare(
        "SELECT COUNT(*) as transaction_count,
                COUNT(DISTINCT t.member_id) as member_count,
                COALESCE(SUM(t.amount_cents), 0) as total_amount_cents
         FROM transactions t
         LEFT JOIN members m ON t.member_id = m.id
         LEFT JOIN products p ON t.product_id = p.id
         {$whereClause}"
    );
    $stmt->execute($params);
    $row = $stmt->fetch();

    return [
        'transaction_count' => (int) $row['transaction_count'],
        'member_count'      => (int) $row['member_count'],
        'total_amount_cents' => (int) $row['total_amount_cents'],
    ];
}
```

Also add `findAllUnsettledByFilters()` in the same file, right after `summarizeUnsettledByFilters()`:

```php
/**
 * Fetch IDs of all unsettled transactions matching filters.
 * Accepted filter keys: date_from, date_to, search, member_id
 *
 * @return string[]
 */
public function findAllUnsettledByFilters(array $filters = []): array
{
    $where = [
        'NOT EXISTS (SELECT 1 FROM settlement_items si JOIN settlements s ON si.settlement_id = s.id WHERE si.transaction_id = t.id AND s.is_cancelled = 0)',
    ];
    $params = [];

    if (isset($filters['date_from'])) {
        $where[] = 't.created_at >= ?';
        $params[] = $filters['date_from'];
    }
    if (isset($filters['date_to'])) {
        $where[] = 't.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (isset($filters['search'])) {
        $escaped = SafeQuery::escapeLike($filters['search']);
        $where[] = "(CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR t.notes LIKE ? OR p.names LIKE ?)";
        $params = array_merge($params, ["%{$escaped}%", "%{$escaped}%", "%{$escaped}%"]);
    }
    if (isset($filters['member_id'])) {
        $where[] = 't.member_id = ?';
        $params[] = $filters['member_id'];
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $stmt = $this->db->prepare(
        "SELECT t.id
         FROM transactions t
         LEFT JOIN members m ON t.member_id = m.id
         LEFT JOIN products p ON t.product_id = p.id
         {$whereClause}
         ORDER BY t.created_at ASC"
    );
    $stmt->execute($params);
    return array_column($stmt->fetchAll(), 'id');
}
```

**Step 2: Restart PHP-FPM**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2
```

**Step 3: Smoke-test via curl**

```bash
curl -s 'http://localhost:8080/api/health' | jq .
```
Expected: `{"status": "ok"}` (or similar).

**Step 4: Commit**

```bash
git add backend/src/Modules/Transactions/Repositories/TransactionsRepository.php
git commit -m "feat(transactions): add summarizeUnsettledByFilters and findAllUnsettledByFilters to repository"
```

---

## Task 2: Backend – Service methods in SettlementsService

**Files:**
- Modify: `backend/src/Modules/Settlements/Services/SettlementsService.php`

**Step 1: Add `previewByFilters()` after `previewSettlement()` (line ~80)**

```php
/**
 * Return lightweight aggregate preview for all unsettled transactions
 * matching the given journal filters.
 *
 * @param array{ date_from?: string, date_to?: string, search?: string, member_id?: string } $filters
 * @return array{ transaction_count: int, member_count: int, total_amount_cents: int }
 */
public function previewByFilters(array $filters): array
{
    return $this->transactionsRepository->summarizeUnsettledByFilters($filters);
}
```

**Step 2: Add `createSettlementByFilters()` after `createSettlement()` (line ~144)**

```php
/**
 * Create a settlement for all unsettled transactions matching the given filters.
 *
 * @param array{ date_from?: string, date_to?: string, search?: string, member_id?: string } $filters
 */
public function createSettlementByFilters(
    array $filters,
    string $settlementDate,
    string $executionDate,
    string $adminUserId,
    ?string $notes = null,
): SettlementDto {
    $transactionIds = $this->transactionsRepository->findAllUnsettledByFilters($filters);
    if (empty($transactionIds)) {
        throw new BusinessRuleException('No unsettled transactions found for the given filters');
    }

    return $this->createSettlement(
        transactionIds: $transactionIds,
        settlementDate: $settlementDate,
        executionDate: $executionDate,
        periodStart: $filters['date_from'] ?? null,
        periodEnd: $filters['date_to'] ?? null,
        manualReason: null,
        notes: $notes,
        adminUserId: $adminUserId,
    );
}
```

**Step 3: Restart PHP-FPM**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2
```

**Step 4: Commit**

```bash
git add backend/src/Modules/Settlements/Services/SettlementsService.php
git commit -m "feat(settlements): add previewByFilters and createSettlementByFilters service methods"
```

---

## Task 3: Backend – Controller methods and routes

**Files:**
- Modify: `backend/src/Modules/Settlements/Controllers/AdminController.php`
- Modify: `backend/src/routes.php`

**Step 1: Add `filterPreview()` method to AdminController after `preview()` (line ~33)**

```php
public function filterPreview(Request $request, Response $response): Response
{
    $params = $request->getQueryParams();

    $filters = [];
    if (isset($params['date_from'])) $filters['date_from'] = $params['date_from'];
    if (isset($params['date_to']))   $filters['date_to']   = $params['date_to'];
    if (isset($params['search']))    $filters['search']    = $params['search'];
    if (isset($params['member_id'])) $filters['member_id'] = $params['member_id'];

    $result = $this->settlementsService->previewByFilters($filters);

    return $this->json($response, $result);
}
```

**Step 2: Add `settleFilter()` method after `filterPreview()`**

```php
public function settleFilter(Request $request, Response $response): Response
{
    $body = $request->getParsedBody() ?? [];
    $adminId = $request->getAttribute('admin_user_id');

    if (!$this->validator->validate($body, [
        'settlement_date' => ['required', 'date'],
        'execution_date'  => ['required', 'date'],
    ])) {
        return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
    }

    $filters = [];
    if (isset($body['date_from'])) $filters['date_from'] = $body['date_from'];
    if (isset($body['date_to']))   $filters['date_to']   = $body['date_to'];
    if (isset($body['search']))    $filters['search']    = $body['search'];
    if (isset($body['member_id'])) $filters['member_id'] = $body['member_id'];

    $settlement = $this->settlementsService->createSettlementByFilters(
        filters: $filters,
        settlementDate: $body['settlement_date'],
        executionDate: $body['execution_date'],
        adminUserId: $adminId,
        notes: $body['notes'] ?? null,
    );

    return $this->json($response, $settlement->toArray(), 201);
}
```

**Step 3: Register routes in `routes.php`**

In `routes.php`, add the two new routes BEFORE the existing `settlements/preview` and `settlements/{id}` lines (to avoid the `{id}` pattern matching "filter-preview" as an ID):

```php
$group->get('/settlements/filter-preview', [SettlementsAdminController::class, 'filterPreview']);
$group->post('/settlements/settle-filter', [SettlementsAdminController::class, 'settleFilter']);
```

**Step 4: Restart PHP-FPM**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2
```

**Step 5: Smoke-test new endpoints with curl**

```bash
# Preview endpoint (no auth yet, expect 401 or data)
curl -sv 'http://localhost:8080/api/admin/settlements/filter-preview' 2>&1 | grep -E "HTTP|{|}"

# Settle-filter endpoint
curl -sv -X POST 'http://localhost:8080/api/admin/settlements/settle-filter' \
  -H 'Content-Type: application/json' \
  -d '{"settlement_date":"2026-02-26","execution_date":"2026-03-05"}' 2>&1 | grep -E "HTTP"
```
Expected: `HTTP/1.1 401` (auth required) — routes are registered.

**Step 6: Commit**

```bash
git add backend/src/Modules/Settlements/Controllers/AdminController.php backend/src/routes.php
git commit -m "feat(settlements): add filterPreview and settleFilter controller methods and routes"
```

---

## Task 4: API tests for new endpoints

**Files:**
- Modify: `e2etests/tests/api/settlements.spec.ts` (add new describe block at end of file)

**Step 1: Read the existing test file to understand auth pattern**

```bash
head -60 e2etests/tests/api/settlements.spec.ts
```

**Step 2: Add test suite for new endpoints**

Add at the bottom of `e2etests/tests/api/settlements.spec.ts`:

```typescript
describe('GET /api/admin/settlements/filter-preview', () => {
  test('returns aggregate stats for unsettled transactions', async ({ request }) => {
    const testId = `prev-${Date.now()}`
    // Create member + 2 transactions
    const memberRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'Filter', last_name: `Prev${testId}`,
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-01',
        preferred_language: 'de',
      },
    })
    const member = await memberRes.json()

    await request.post(`/api/admin/members/${member.id}/transactions`, {
      data: { amount_cents: 500, transaction_type: 'purchase', notes: `fp-note-${testId}` },
    })
    await request.post(`/api/admin/members/${member.id}/transactions`, {
      data: { amount_cents: 300, transaction_type: 'purchase', notes: `fp-note2-${testId}` },
    })

    const res = await request.get('/api/admin/settlements/filter-preview')
    expect(res.status()).toBe(200)
    const body = await res.json()
    expect(body).toHaveProperty('transaction_count')
    expect(body).toHaveProperty('member_count')
    expect(body).toHaveProperty('total_amount_cents')
    expect(typeof body.transaction_count).toBe('number')
    expect(body.transaction_count).toBeGreaterThanOrEqual(2)
    expect(body.total_amount_cents).toBeGreaterThanOrEqual(800)
  })

  test('search filter reduces results', async ({ request }) => {
    const uniqueNote = `uniq-srch-${Date.now()}`
    const memberRes = await request.post('/api/admin/members', {
      data: {
        first_name: 'SearchPrev', last_name: `Test${Date.now()}`,
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-01',
        preferred_language: 'de',
      },
    })
    const member = await memberRes.json()
    await request.post(`/api/admin/members/${member.id}/transactions`, {
      data: { amount_cents: 100, transaction_type: 'purchase', notes: uniqueNote },
    })

    const res = await request.get(`/api/admin/settlements/filter-preview?search=${encodeURIComponent('SearchPrev')}`)
    expect(res.status()).toBe(200)
    const body = await res.json()
    expect(body.transaction_count).toBeGreaterThanOrEqual(1)
  })
})

describe('POST /api/admin/settlements/settle-filter', () => {
  test('creates settlement for all unsettled transactions matching filters', async ({ request }) => {
    const testId = `sf-${Date.now()}`
    // Use a search param to isolate just our test data
    const uniqueName = `SettleFilterTest${testId}`
    const memberRes = await request.post('/api/admin/members', {
      data: {
        first_name: uniqueName, last_name: 'Member',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-01',
        preferred_language: 'de',
      },
    })
    const member = await memberRes.json()
    await request.post(`/api/admin/members/${member.id}/transactions`, {
      data: { amount_cents: 400, transaction_type: 'purchase', notes: `sf-tx-${testId}` },
    })

    const today = new Date().toISOString().split('T')[0]
    const exec = new Date(Date.now() + 7 * 86400000).toISOString().split('T')[0]

    const res = await request.post('/api/admin/settlements/settle-filter', {
      data: { search: uniqueName, settlement_date: today, execution_date: exec },
    })
    expect(res.status()).toBe(201)
    const settlement = await res.json()
    expect(settlement).toHaveProperty('id')
    expect(settlement.transaction_count).toBeGreaterThanOrEqual(1)
    expect(settlement.total_amount_cents).toBeGreaterThanOrEqual(400)
  })

  test('returns 422 when settlement_date or execution_date missing', async ({ request }) => {
    const res = await request.post('/api/admin/settlements/settle-filter', {
      data: { settlement_date: '2026-02-26' }, // missing execution_date
    })
    expect(res.status()).toBe(422)
  })
})
```

**Step 3: Run the new tests**

```bash
cd e2etests && npm test -- --grep "filter-preview\|settle-filter" --workers=1
```
Expected: Tests pass (or 401 if auth setup needed — check existing settlement test auth pattern and mirror it).

**Step 4: Run full settlements test file**

```bash
cd e2etests && npm test -- tests/api/settlements.spec.ts --workers=4
```
Expected: All pass.

**Step 5: Commit**

```bash
git add e2etests/tests/api/settlements.spec.ts
git commit -m "test(settlements): add API tests for filter-preview and settle-filter endpoints"
```

---

## Task 5: Frontend – New service functions in settlements.ts

**Files:**
- Modify: `admin-frontend/src/services/settlements.ts`

**Step 1: Add `SettlementFilterPreview` interface and `getSettlementFilterPreview()` function**

Add after the `createSettlement` function (after line ~179):

```typescript
export interface SettlementFilterPreview {
  transaction_count: number
  member_count: number
  total_amount_cents: number
}

/**
 * Get aggregate preview stats for all unsettled transactions matching the given filters.
 * Used to populate the "Abrechnung (alle)" confirm modal.
 */
export async function getSettlementFilterPreview(
  dateFrom?: string,
  dateTo?: string,
  search?: string,
  memberId?: string,
): Promise<SettlementFilterPreview> {
  const params: Record<string, string> = {}
  if (dateFrom)  params.date_from  = dateFrom
  if (dateTo)    params.date_to    = dateTo
  if (search)    params.search     = search
  if (memberId)  params.member_id  = memberId

  const result = await get<SettlementFilterPreview>('/admin/settlements/filter-preview', { params })
  return result as SettlementFilterPreview
}

/**
 * Create a settlement for all unsettled transactions matching the given filters.
 */
export async function createSettlementByFilters(
  settlementDate: string,
  executionDate: string,
  dateFrom?: string,
  dateTo?: string,
  search?: string,
  memberId?: string,
  notes?: string,
): Promise<Settlement> {
  const payload: Record<string, string | undefined> = {
    settlement_date: settlementDate,
    execution_date: executionDate,
  }
  if (dateFrom)  payload.date_from  = dateFrom
  if (dateTo)    payload.date_to    = dateTo
  if (search)    payload.search     = search
  if (memberId)  payload.member_id  = memberId
  if (notes)     payload.notes      = notes

  const apiResponse = await post<Settlement>('/admin/settlements/settle-filter', payload)
  const response = apiResponse as any
  if (response && typeof response === 'object') {
    if ('id' in response && 'settlement_date' in response) return response as Settlement
    if ('data' in response && response.data) return response.data as Settlement
  }
  throw new Error('Invalid response from settle-filter API')
}
```

**Step 2: Verify TypeScript**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | head -20
```
Expected: no errors.

**Step 3: Commit**

```bash
git add admin-frontend/src/services/settlements.ts
git commit -m "feat(settlements): add getSettlementFilterPreview and createSettlementByFilters service functions"
```

---

## Task 6: Frontend – Update SettlementConfirmModal to accept preview stats

The modal currently derives stats from a `transactions` array. For "settle-all" there are no individual transaction objects — only aggregate stats from the preview API. Update the modal to accept either.

**Files:**
- Modify: `admin-frontend/src/components/modals/SettlementConfirmModal.tsx`

**Step 1: Update props interface and stat computation**

Replace the entire props interface and the three derived-stat lines:

Old interface (lines 12–19):
```typescript
export interface SettlementConfirmModalProps {
  isOpen: boolean
  transactions: GlobalTransaction[]
  onConfirm: () => void
  onCancel: () => void
  isLoading: boolean
  error?: string | null
}
```

New interface:
```typescript
export interface SettlementFilterPreview {
  transaction_count: number
  member_count: number
  total_amount_cents: number
}

export interface SettlementConfirmModalProps {
  isOpen: boolean
  /** Provide either `transactions` (settle-selected) or `preview` (settle-all) */
  transactions?: GlobalTransaction[]
  preview?: SettlementFilterPreview
  onConfirm: () => void
  onCancel: () => void
  isLoading: boolean
  error?: string | null
}
```

Replace the three stat lines (lines 38–40):
```typescript
const transactionCount = transactions.length
const memberCount = new Set(transactions.map((tx) => tx.member_id)).size
const totalCents = transactions.reduce((sum, tx) => sum + tx.amount_cents, 0)
```

With:
```typescript
const transactionCount = preview?.transaction_count ?? transactions?.length ?? 0
const memberCount = preview?.member_count ?? new Set(transactions?.map((tx) => tx.member_id) ?? []).size
const totalCents = preview?.total_amount_cents ?? transactions?.reduce((sum, tx) => sum + tx.amount_cents, 0) ?? 0
```

Also remove the `GlobalTransaction` import if `transactions` becomes optional (check if it's still needed for the type — keep it since the prop type still references it).

**Step 2: Verify TypeScript**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | head -20
```
Expected: no errors.

**Step 3: Commit**

```bash
git add admin-frontend/src/components/modals/SettlementConfirmModal.tsx
git commit -m "feat(settlements): extend SettlementConfirmModal to accept preview stats for settle-all flow"
```

---

## Task 7: Frontend – Wire up "Abrechnung (alle)" in JournalPage

**Files:**
- Modify: `admin-frontend/src/pages/JournalPage.tsx`

**Step 1: Add new imports**

Add to the imports from `'../services/settlements'`:
```typescript
import {
  createSettlement,
  createSettlementByFilters,
  getSettlementFilterPreview,
  type SettlementFilterPreview,
} from '../services/settlements'
```

Add `SettlementFilterPreview` to the `SettlementConfirmModal` import:
```typescript
import { SettlementConfirmModal, type SettlementFilterPreview as ModalFilterPreview } from '../components/modals/SettlementConfirmModal'
```

(Or use the same name — whichever avoids duplication; both types have the same shape, so one import works.)

**Step 2: Add `settleAllPreview` state**

After the existing `confirmError` state (around line 95):
```typescript
const [settleAllPreview, setSettleAllPreview] = useState<SettlementFilterPreview | null>(null)
const [settleAllLoading, setSettleAllLoading] = useState(false)
```

**Step 3: Rewrite `handleSettleAll`**

Replace the body of `handleSettleAll()` to call the preview API:

```typescript
const handleSettleAll = async () => {
  setSettleAllLoading(true)
  try {
    const preview = await getSettlementFilterPreview(
      dateFrom || undefined,
      dateTo || undefined,
      search || undefined,
    )
    if (preview.transaction_count === 0) {
      setState((prev) => ({ ...prev, error: t('journal.settlementNoOpen') }))
      return
    }
    setSettleAllPreview(preview)
    setConfirmError(null)
    setConfirmModalOpen(true)
  } catch (err) {
    setState((prev) => ({ ...prev, error: err instanceof Error ? err.message : 'Failed to load preview' }))
  } finally {
    setSettleAllLoading(false)
  }
}
```

**Step 4: Update `handleConfirmSettlement` to branch on settle-all vs settle-selected**

Replace the `createSettlement(...)` call inside `handleConfirmSettlement`:

```typescript
if (settleAllPreview) {
  await createSettlementByFilters(
    today,
    executionDateStr,
    dateFrom || undefined,
    dateTo || undefined,
    search || undefined,
  )
} else {
  await createSettlement(
    pendingTransactions.map((tx) => tx.id),
    today,
    executionDateStr,
  )
}
```

**Step 5: Reset `settleAllPreview` on modal close**

Wherever `setConfirmModalOpen(false)` is called (success path and cancel), also call:
```typescript
setSettleAllPreview(null)
```

**Step 6: Update the modal `<SettlementConfirmModal>` usage**

Pass `preview` and `transactions` correctly:
```tsx
<SettlementConfirmModal
  isOpen={confirmModalOpen}
  transactions={settleAllPreview ? undefined : pendingTransactions}
  preview={settleAllPreview ?? undefined}
  onConfirm={handleConfirmSettlement}
  onCancel={() => { setConfirmModalOpen(false); setSettleAllPreview(null); setConfirmError(null) }}
  isLoading={confirmLoading}
  error={confirmError}
/>
```

**Step 7: Update the "Abrechnung (alle)" button to reflect loading state**

```tsx
<button
  data-testid="journal-settlement-all-btn"
  onClick={handleSettleAll}
  disabled={settleAllLoading}
  style={{
    padding: '8px 16px',
    backgroundColor: settleAllLoading ? '#6b7280' : '#10b981',
    color: '#ffffff',
    border: 'none',
    borderRadius: 6,
    fontSize: 14,
    fontWeight: 500,
    cursor: settleAllLoading ? 'not-allowed' : 'pointer',
    transition: 'background-color 0.15s',
  }}
  onMouseEnter={(e) => {
    if (!settleAllLoading) e.currentTarget.style.backgroundColor = '#059669'
  }}
  onMouseLeave={(e) => {
    if (!settleAllLoading) e.currentTarget.style.backgroundColor = '#10b981'
  }}
>
  {settleAllLoading ? '...' : `+ ${t('journal.settlementAll')}`}
</button>
```

**Step 8: Verify TypeScript**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | head -20
```
Expected: no errors.

**Step 9: Commit**

```bash
git add admin-frontend/src/pages/JournalPage.tsx
git commit -m "feat(journal): wire Abrechnung (alle) to filter-preview and settle-filter APIs"
```

---

## Task 8: E2E test for settle-all flow via journal UI

**Files:**
- Modify: `e2etests/tests/admin/journal.spec.ts`

**Step 1: Add a settle-all E2E test**

Add at the end of the settlement-related section (after the existing settlement test):

```typescript
test('settle-all creates settlement for all unsettled transactions via filter-based API', async ({ page }) => {
  const testId = `settle-all-${Date.now()}`
  const uniqueName = `SettleAll${testId}`

  // Setup: create member + transactions via API
  const memberRes = await page.request.post('/api/admin/members', {
    data: {
      first_name: uniqueName, last_name: 'E2E',
      iban: 'DE89370400440532013000',
      mandate_signed_at: '2024-01-01',
      preferred_language: 'de',
    },
  })
  const member = await memberRes.json()
  await page.request.post(`/api/admin/members/${member.id}/transactions`, {
    data: { amount_cents: 250, transaction_type: 'purchase', notes: `sa-note-${testId}` },
  })

  // Navigate to journal, search for our test member
  await page.goto('/journal')
  // Wait for page load
  await page.waitForSelector('[data-testid="journal-settlement-all-btn"]')

  // Search for our specific test member to isolate the settle-all scope
  const searchInput = page.getByTestId('journal-search-input')
  await searchInput.fill(uniqueName)
  await page.waitForResponse((r) => r.url().includes('/api/admin/transactions'))

  // Click "Abrechnung (alle)"
  await page.getByTestId('journal-settlement-all-btn').click()

  // Modal should appear with correct data
  await expect(page.getByTestId('journal-settlement-confirm-modal')).toBeVisible()
  const txCount = page.getByTestId('journal-settlement-confirm-transaction-count')
  await expect(txCount).toBeVisible()
  const count = parseInt(await txCount.innerText())
  expect(count).toBeGreaterThanOrEqual(1)

  // Confirm settlement
  await page.getByTestId('journal-settlement-confirm-submit-btn').click()

  // Should navigate to settlements page
  await page.waitForURL('**/settlements')

  // Verify no error remained (by checking we're on settlements page)
  expect(page.url()).toContain('/settlements')
})
```

**Step 2: Run the new E2E test**

```bash
cd e2etests && npm test -- --grep "settle-all creates settlement" --workers=1
```
Expected: PASS.

**Step 3: Run full journal test suite**

```bash
cd e2etests && npm test -- tests/admin/journal.spec.ts --workers=4
```
Expected: All pass.

**Step 4: Commit**

```bash
git add e2etests/tests/admin/journal.spec.ts
git commit -m "test(journal): add E2E test for settle-all filter-based settlement flow"
```

---

## Task 9: Full test suite verification

**Step 1: Run all tests**

```bash
cd e2etests && npm test -- --workers=4
```
Expected: All existing tests pass, no regressions.

**Step 2: Final commit if anything was adjusted**

```bash
git add -p  # Review any remaining changes
git commit -m "fix: final adjustments after full test suite run"
```

---

## Summary of Changes

| Component | What Changed |
|---|---|
| `TransactionsRepository.php` | + `summarizeUnsettledByFilters()`, `findAllUnsettledByFilters()` |
| `SettlementsService.php` | + `previewByFilters()`, `createSettlementByFilters()` |
| `AdminController.php` (Settlements) | + `filterPreview()`, `settleFilter()` |
| `routes.php` | + `GET /settlements/filter-preview`, `POST /settlements/settle-filter` |
| `settlements.ts` (frontend) | + `SettlementFilterPreview`, `getSettlementFilterPreview()`, `createSettlementByFilters()` |
| `SettlementConfirmModal.tsx` | `transactions` prop made optional; `preview` prop added |
| `JournalPage.tsx` | `handleSettleAll` uses preview API; confirm uses filter-based create |
| `settlements.spec.ts` (API) | + filter-preview and settle-filter API tests |
| `journal.spec.ts` (E2E) | + settle-all UI flow test |
