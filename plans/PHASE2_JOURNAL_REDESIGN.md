# Phase 2: Journal Page - Design Correction

**Date**: 2026-01-27
**Status**: Planning (design clarification after initial misconception)
**Issue**: Initial implementation confused two separate features into one page

---

## Problem Statement

Initial implementation created a hybrid page that mixed two distinct features:
1. **UC-A20**: Member transaction detail (should be a modal in Members page)
2. **Journal Page**: Global transaction journal (standalone page)

---

## Correct Feature Architecture

### Feature 1: UC-A20 - Member Transaction Tab (Modal/Detail)

**Location**: `Members` page
**UI Pattern**: Modal or side panel
**Trigger**: Click icon/button in member table row or member balance
**Purpose**: View specific member's transaction history

**Implements**:
- Current member balance
- Transaction list (all transactions for that member)
- Filter by type (all, purchase, correction)
- Filter by date range
- Settlement history
- Expand/collapse transaction details

**API Used**:
- `GET /members/{memberId}/transactions` (with filtering)

**Related Use Cases**:
- UC-A20: View Tab (member transaction history)

---

### Feature 2: Journal Page (Buchungsjournal) - Global Transaction Log

**Location**: Separate page `/journal`
**UI Pattern**: Standalone page with transaction table
**Purpose**: View ALL transactions across all members

**Implements**:
- Filter tabs: All / Purchases / Corrections
- Table columns: Date, Member, RFID, Product, Qty, Unit Price, Total
- Pagination (40+ transactions)
- Sorting by date

**API Challenge**:
- No `GET /transactions` list endpoint exists
- Only `GET /transactions/export` (CSV download)
- Only `GET /members/{memberId}/transactions` (per-member)

**Recommendations**:
1. **Option A**: Implement as "Recent Transactions" page (summary from dashboard)
   - Show last N transactions from GET /dashboard → recent_transactions
   - Limited set, not full journal
   - Simpler, matches current API

2. **Option B**: Backend enhancement (future)
   - Add `GET /transactions` endpoint to list all transactions
   - Paginated, filterable
   - Matches prototype exactly
   - Requires ADR discussion + backend implementation

3. **Option C**: Member-aggregated view
   - Fetch all members → fetch each member's transactions → aggregate + paginate
   - Expensive but works with current API
   - Not recommended (N+1 query problem)

---

## Implementation Strategy (REVISED)

### Phase 2 Scope Decision

For Phase 2, recommend **deferring Journal page** to Phase 3+:

**Reasoning**:
1. **API gap**: No backend endpoint for global transactions
2. **Low priority**: Members + Products + Settlements + Statistics cover primary workflows
3. **Architectural question**: Needs decision on implementation approach (A, B, or C above)
4. **Better approach**: Implement UC-A20 (member transactions modal) first in Members page

---

## Revised Phase 2 Plan

### Immediate Change (This Sprint)

**REVERT** the current Journal page implementation:
```
❌ Delete: e2etests/tests/admin/journal.spec.ts
❌ Delete: e2etests/pages/JournalPage.ts
❌ Delete: admin-frontend/src/services/journal.ts
❌ Delete: admin-frontend/src/pages/JournalPage.tsx (keep placeholder)
❌ Remove: authenticatedJournalPage fixture from pageObjects.ts
```

**IMPLEMENT** UC-A20 in Members page instead:
```
✅ Create: TransactionModal component or TransactionDetailPanel
✅ Create: transactions.ts service (handles member transactions API)
✅ Add: Transaction icon/button to each member row (Members page)
✅ Add: E2E tests for "View Transactions" in members.spec.ts
```

### Future Work (Phase 3+)

**After decisions made**:
- Decision required: Which Journal implementation approach (A/B/C)?
- Create backend task if Option B chosen
- Implement Journal page once API is ready

---

## Corrected Feature Map

| Feature | Location | Type | Use Case | Phase |
|---------|----------|------|----------|-------|
| **Member Transactions (Tab)** | Members page (modal) | Detail panel | UC-A20 | **Phase 2** ✅ |
| **Journal (All Transactions)** | /journal (page) | Standalone page | - | Phase 3+ |
| **Recent Transactions** | Dashboard | Summary | UC-A80 | Phase 2 ✅ |

---

## API Status

### Current APIs (Ready)
- ✅ `GET /members/{id}/transactions` - Member transaction history per UC-A20
- ✅ `GET /dashboard → recent_transactions` - Recent transactions summary
- ✅ `GET /transactions/export` - Export transactions as CSV

### Missing APIs (Requires Discussion)
- ❌ `GET /transactions` - List all transactions globally
  - **Needed for**: Global Journal page
  - **Status**: Not implemented, would require backend work
  - **Alternative**: Use `/dashboard` recent transactions (limited set)

---

## Action Items

1. **Immediate** (This session):
   - [ ] Decide: Is Journal page in scope for Phase 2?
   - [ ] If NO: Revert Journal implementation, implement UC-A20 instead
   - [ ] If YES: Decision on API approach (A/B/C) and backend coordination

2. **If proceeding with UC-A20** (Recommended):
   - [ ] Delete Journal page files (commit: "Revert journal page - focusing on UC-A20 implementation")
   - [ ] Create TransactionModal component
   - [ ] Add transaction icon to member rows
   - [ ] Create E2E tests for UC-A20 workflow
   - [ ] Verify with product/design requirements

3. **If deferring Journal page**:
   - [ ] Document decision with rationale
   - [ ] Mark as Phase 3+ future work
   - [ ] Create backend task for `GET /transactions` API (if Option B chosen)

---

---

## 🚨 DECISION: OPTION 1 SELECTED ✅

**Implement UC-A20 Modal in Members Page** (not standalone Journal page)

### Immediate Actions

1. **DELETE**:
   ```bash
   ❌ e2etests/tests/admin/journal.spec.ts
   ❌ e2etests/pages/JournalPage.ts
   ❌ admin-frontend/src/services/journal.ts
   ❌ admin-frontend/src/pages/JournalPage.tsx (replace with placeholder)
   ❌ authenticatedJournalPage fixture
   ```
   Commit: "Revert: Remove Journal page, prepare for UC-A20 implementation"

2. **IMPLEMENT**:
   ```bash
   ✅ Create: admin-frontend/src/components/modals/TransactionModal.tsx
   ✅ Create: admin-frontend/src/services/transactions.ts (UC-A20 API integration)
   ✅ Update: admin-frontend/src/pages/MembersPage.tsx (add transaction icon)
   ✅ Create: e2etests/tests/admin/members-uc-a20-transactions.spec.ts
   ✅ Update: e2etests/pages/MembersPage.ts (transaction methods)
   ```
   Commit: "Implement UC-A20: Member Transaction Modal (View Transactions in Members page)"

---

## 🎯 CRITICAL BACKEND REQUIREMENT ⚠️

### BLOCKER: Missing Global Transactions API

**For Future Journal Page Implementation**, backend MUST provide:

```
GET /api/admin/transactions
  Query Parameters:
    - date_from: date (optional, YYYY-MM-DD)
    - date_to: date (optional, YYYY-MM-DD)
    - type: all | purchase | correction (default: all)
    - member_id: UUID (optional, filter by member)
    - page: integer (default: 1)
    - per_page: integer (default: 50)

  Response: {
    items: [
      {
        id: UUID,
        timestamp: ISO date-time,
        member_id: UUID,
        member_name: string,
        card_uid: string (RFID),
        product_id: UUID,
        product_name: string,
        category_name: string,
        quantity: integer,
        unit_price_cents: integer,
        total_amount_cents: integer,
        type: "purchase" | "correction",
        settlement_id: UUID (nullable),
        created_at: ISO date-time
      }
    ],
    pagination: {
      page: integer,
      per_page: integer,
      total: integer,
      total_pages: integer
    }
  }
```

**Columns for global journal view** (from prototype):
- Date, Member, RFID, Product, Quantity, Unit Price, Total

**Required Filtering**:
- ✅ Date range (from/to)
- ✅ Transaction type (all/purchase/correction)
- ✅ Optional member filter
- ✅ Pagination

**When needed**: Phase 3+ (after UC-A20 modal complete)

**Backend Task**:
- [ ] Create `GET /api/admin/transactions` endpoint
- [ ] Implement filtering logic
- [ ] Add E2E tests for endpoint
- [ ] Document in OpenAPI spec

---

## Phase 2 Implementation Priority

### Current (Phase 2 - In Progress)
1. ✅ Members page (complete)
2. ✅ Products page (complete)
3. ✅ UI System (complete)
4. 🔄 **UC-A20 Modal** (replaces Journal page) ← NEXT
5. Settlements page
6. Statistics page

### Deferred (Phase 3+)
- **Journal Page** (awaiting global transactions API)
  - Depends on: `GET /api/admin/transactions` backend implementation
  - Blocked: No global transaction list endpoint currently exists

---

## Summary

✅ **Phase 2**: Implement UC-A20 "View Transactions" modal in Members page
  - Uses existing `GET /members/{id}/transactions` API
  - No backend work required
  - Can proceed immediately

⏸️ **Phase 3+**: Implement global Journal page
  - **BLOCKED**: Requires new `GET /api/admin/transactions` endpoint
  - Must be implemented in backend first
  - Then add `/journal` page to admin frontend

🚨 **ACTION ITEM FOR BACKEND TEAM**:
- [ ] Create task: "Implement global transactions API endpoint"
- [ ] Spec: `GET /api/admin/transactions` with date/type/member filtering
- [ ] Timeline: Needed for Phase 3 Journal page
- [ ] Link: This plan document
