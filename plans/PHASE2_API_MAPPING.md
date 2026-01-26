# Phase 2: Admin Frontend - API Endpoint Mapping

This document maps the actual backend API endpoints and data structures to the prototype UI pages. This is the source-of-truth for Phase 2 implementation.

**Principles**:
- Backend API = source-of-truth for functionality
- Prototype = source-of-truth for visual design
- If API supports more than prototype shows → implement all API capabilities
- If API doesn't support prototype feature → skip or note as future enhancement

---

## Overview: Pages vs Endpoints

| Prototype Page | API Module | Primary Endpoints | Notes |
|---|---|---|---|
| **Members** | Members | GET /members, POST /members, PATCH /members/{id}, DELETE /members/{id}, GET /members/{id}/transactions | Full CRUD + member transactions |
| **Products** | Products | GET /products, POST /products, PATCH /products/{id}, DELETE /products/{id}, GET /categories | CRUD + categories |
| **Journal** (Buchungsjournal) | Transactions | GET /members/{id}/transactions, POST /members/{id}/transactions (corrections) | Member transaction history + create corrections |
| **Settlements** | Settlements | GET /settlements, POST /settlements, GET /settlements/preview, GET /settlements/{id}, POST /settlements/{id}/finalize, POST /settlements/{id}/cancel, GET /settlements/{id}/export/csv, GET /settlements/{id}/export/sepa-xml | Full settlement workflow |
| **Statistics** (Statistik) | Reports + Dashboard | GET /dashboard, GET /reports/{reportType} (revenue, consumption, transactions), GET /reports/member-ranking, GET /reports/terminal-activity | Dashboard + detailed reports |

---

## Page 1: Members (Mitglieder)

### Prototype UI Elements
```
[Summary Cards]
├─ 6 Mitglieder (member count)
├─ 329,00 € Offene Deckel gesamt (total balance)
└─ 31.12.2025 Letzte Abrechnung (last settlement)

[Members Table]
├─ Search box + "Neues Mitglied" button
└─ Columns: Name, RFID, IBAN, BIC, Mitglied seit, Deckel, Aktionen
   └─ Aktionen: Offene Posten, Bearbeiten, Löschen
```

### API Endpoints

**1. GET /members** - List members (paginated)
```
Request:
  page: 1
  per_page: 25
  status: all | active | inactive
  balance: all | with_balance | zero_balance
  sepa_status: all | valid | invalid
  search: string (first_name, last_name, card_uid)
  sort_by: name_asc | name_desc | balance_asc | balance_desc | created_at_desc

Response: MemberListItem[]
  - id (UUID)
  - first_name, last_name
  - balance_cents (int, in cents)
  - card_uid (RFID)
  - iban_masked: "DE89****3000" (masked for list)
  - is_active (boolean)
  - is_sepa_valid (boolean)
  - created_at (ISO date-time)
  - pagination: { page, per_page, total, total_pages }
```

**2. POST /members** - Create member
```
Request:
  first_name: string (required, max 100)
  last_name: string (required, max 100)
  email: string (optional)
  preferred_language: string (required, ISO 639-1: de, en, etc.)
  iban: string (required, min 15, max 34 chars)
  mandate_signed_at: date (required, YYYY-MM-DD, not in future)
  mandate_reference: string (optional, max 35, defaults to UUID-no-hyphens)

Response: Member (full object)
  - id, first_name, last_name, email, card_uid
  - preferred_language, iban, mandate_reference, mandate_signed_at
  - is_active, is_sepa_valid, balance_cents
  - created_at, updated_at
```

**3. GET /members/{memberId}** - Get member details
```
Response: Member (full object with unmasked IBAN)
  - Same fields as POST response
  - iban: full IBAN (not masked)
```

**4. PATCH /members/{memberId}** - Update member
```
Request (all optional):
  first_name, last_name, email, preferred_language
  iban, mandate_reference, mandate_signed_at
  is_active (boolean)

Response: Member (updated object)
```

**5. DELETE /members/{memberId}** - Delete member (soft-delete)
```
Response: { message: "Member deleted successfully" }
  - Sets is_active = false (soft delete)
```

**6. GET /members/{memberId}/transactions** - Get member transaction history
```
Request parameters:
  date_from: date (optional)
  date_to: date (optional)
  type: all | purchase | correction
  include_settled: boolean (default false)

Response: MemberTransactionHistory
  - member_id (UUID)
  - current_balance_cents (int)
  - transactions: array of
    - id, date (ISO date-time)
    - type: purchase | correction
    - description (string)
    - amount_cents (int)
    - running_total_cents (int)
    - settlement_id (UUID or null)
  - settlements: array of
    - id, date, amount_cents
    - type: sepa | manual
```

### API → Prototype Mapping

| Prototype UI | API Data | Notes |
|---|---|---|
| Summary: "6 Mitglieder" | Count from GET /members (or dashboard metric) | Total active members |
| Summary: "329,00 € Offene Deckel gesamt" | Sum of balance_cents from GET /members or dashboard | Outstanding balance |
| Summary: "31.12.2025 Letzte Abrechnung" | GET /dashboard → system_status.last_settlement_date | NOT from /members |
| Search box | GET /members?search=... | Searches first_name, last_name, card_uid |
| Filter buttons | Status, balance, sepa_status query params | Advanced filters beyond prototype |
| Table: Name | first_name + " " + last_name | Combined full name |
| Table: RFID | card_uid | RFID card identifier |
| Table: IBAN | iban_masked | Masked format "DE89****3000" |
| Table: BIC | NOT IN API | **API doesn't return BIC** → Remove from list |
| Table: Mitglied seit | created_at | ISO date, format as DD.MM.YYYY |
| Table: Deckel | balance_cents | Format as price (€) in green/orange |
| Button: "Offene Posten" | GET /members/{id}/transactions | Show in modal |
| Button: "Bearbeiten" | PATCH /members/{id} | Show in modal form |
| Button: "Löschen" | DELETE /members/{id} | Soft delete + confirmation |
| Button: "Neues Mitglied" | POST /members | Show in modal form |

### Key Differences from Prototype

⚠️ **Prototype shows BIC column, but API doesn't return BIC data**
- Solution: Remove BIC from list view (still visible in detail/edit modal if stored separately, but not in API)

✅ **API has more filtering options than prototype shows**
- Filters: status (active/inactive), balance (with balance/zero balance), sepa_status (valid/invalid)
- Recommendation: Add filter dropdown in Phase 2+

✅ **Member creation requires mandate_signed_at**
- Prototype doesn't show this field
- Solution: Add mandate signature date field to create modal

### Implementation Checklist

- [ ] GET /members list endpoint with pagination + search
- [ ] Display balance with color coding (green if 0, orange if negative)
- [ ] Format dates as DD.MM.YYYY
- [ ] Create member modal (first_name, last_name, email, language, iban, mandate_signed_at)
- [ ] Edit member modal (same fields)
- [ ] Delete confirmation modal
- [ ] "Offene Posten" modal showing transaction history
- [ ] Filter by status (active/inactive) if implementing advanced filters
- [ ] Handle missing BIC field (remove from list)
- [ ] Format IBAN as masked version in list

---

## Page 2: Products (Produkte)

### Prototype UI Elements
```
[Filters]
├─ Search box
├─ Category dropdown: "Alle Kategorien", "Getränke", "Sauna", "Snacks"
├─ Status dropdown: "Alle Status", "Aktiv", "Inaktiv"
└─ "Neues Produkt" button

[Products Table]
└─ Columns: Produkt-ID, Name, Kategorie, Preis, Status, Erstellt am, Aktionen
   └─ Aktionen: Bearbeiten, Löschen

[Summary]
└─ "13 von 13 Produkten · 11 aktiv · 2 inaktiv"
```

### API Endpoints

**1. GET /categories** - List categories
```
Request:
  (no parameters)

Response: Category[]
  - id (UUID)
  - names: { de: "Getränke", en: "Beverages" } (MultilingualText)
  - display_order (integer)
  - is_active (boolean)
  - product_count (integer) ← useful for summary
  - created_at, updated_at
```

**2. GET /products** - List products (paginated)
```
Request:
  page: 1
  per_page: 25
  status: all | active | inactive
  category_id: UUID (optional)
  search: string (product names)
  sort_by: name_asc | name_desc | price_asc | price_desc | category

Response: Product[]
  - id (UUID)
  - category_id (UUID)
  - names: { de: "Pils 0,5l", en: "Pilsner 0.5L" } (MultilingualText)
  - descriptions: { de: "...", en: "..." } (optional)
  - price_cents (int, in cents)
  - is_active (boolean)
  - created_at, updated_at
  - pagination: { page, per_page, total, total_pages }
```

**3. POST /products** - Create product
```
Request:
  names: { de: "Pils 0,5l" } (required, at least one language)
  descriptions: { de: "..." } (optional)
  price_cents: integer (required, min 1, max 999999)
  category_id: UUID (required)

Response: Product (full object)
```

**4. GET /products/{productId}** - Get product details
```
Response: Product (full object)
```

**5. PATCH /products/{productId}** - Update product
```
Request (all optional):
  names: MultilingualText
  descriptions: MultilingualText
  price_cents: integer
  category_id: UUID
  is_active: boolean

Response: Product (updated object)
```

**6. DELETE /products/{productId}** - Delete product (soft-delete)
```
Response: { message: "Product deleted successfully" }
```

**7. POST /categories** - Create category
```
Request:
  names: { de: "Neue Kategorie" } (required)
  is_active: boolean (default true)

Response: Category
```

**8. GET /categories/{categoryId}** - Get category details
```
Response: Category
```

**9. PATCH /categories/{categoryId}** - Update category
```
Request (optional):
  names: MultilingualText
  is_active: boolean

Response: Category
```

**10. POST /categories/reorder** - Reorder categories
```
Request:
  category_ids: [UUID, UUID, ...]

Response: { message: "Categories reordered" }
```

### API → Prototype Mapping

| Prototype UI | API Data | Notes |
|---|---|---|
| Category dropdown | GET /categories → names[locale] | Multilingual names |
| Status filter | GET /products?status=active\|inactive\|all | Filter by is_active |
| Search box | GET /products?search=... | Search in product names |
| Sort options | GET /products?sort_by=... | Multiple sort options available |
| Table: Produkt-ID | id (UUID) | Can show short version or full UUID |
| Table: Name | names[locale] | Use admin's preferred locale |
| Table: Kategorie | Get category name from category_id lookup | Need to fetch category or include in response |
| Table: Preis | price_cents | Format as €X,XX |
| Table: Status | is_active | "Aktiv" / "Inaktiv" |
| Table: Erstellt am | created_at | Format as DD.MM.YYYY |
| Summary: "X aktiv / Y inaktiv" | Count is_active true/false in response | Can also use GET /categories → product_count |

### Key Notes

✅ **Multilingual support**: Products have names/descriptions in multiple languages (JSON object with ISO 639-1 keys)
- Implementation: Use admin's preferred_language to display, but store all translations

✅ **Prototype shows Product IDs (PRD-001, etc.)**
- API returns UUID (550e8400-e29b-41d4-a716-446655440000)
- Options: Display short UUID, or calculate SKU format locally

✅ **Categories included**: Full CRUD operations available

### Implementation Checklist

- [ ] GET /categories on page load
- [ ] GET /products with pagination, filtering, search, sorting
- [ ] Category filter dropdown (populated from /categories)
- [ ] Status filter (active/inactive/all)
- [ ] Create product modal (name in multiple languages, category, price)
- [ ] Edit product modal (same as create)
- [ ] Delete product with confirmation
- [ ] Display product count summary (total, active, inactive)
- [ ] Format prices as €X,XX
- [ ] Handle multilingual product names (display in locale)
- [ ] Optional: Add category management (create/edit/reorder categories)

---

## Page 3: Journal (Buchungsjournal)

### Prototype UI Elements
```
[Filter Tabs]
├─ Alle (all transactions)
├─ Konsumationen (purchases only)
└─ Korrekturen (corrections only)

[Transaction Table]
└─ Columns: Datum, Mitglied, RFID, Artikel, Anzahl, Einzelpreis, Gesamt
   └─ "40 Buchungen · Seite 1 von 4" (pagination info)

[Pagination]
└─ "Zurück" (disabled on first page), "Weiter" buttons
```

### API Endpoints

**1. GET /transactions/export** - Export all transactions as CSV
```
Request:
  date_from: date (optional)
  date_to: date (optional)
  member_id: UUID (optional)
  type: all | purchase | correction

Response: CSV file (download)
```

**NOTE**: There's no dedicated endpoint to list all transactions!
- Transactions are accessed via GET /members/{memberId}/transactions
- This only shows transactions for a specific member

### Challenge: Prototype Shows Global Transaction Journal

The prototype shows a "Buchungsjournal" (transaction journal) with ALL transactions, paginated across 4 pages (40 bookings).

**But the API only provides**:
- GET /members/{memberId}/transactions - Per-member transaction history
- GET /transactions/export - Export (not list)

**Options**:
1. **Change UI to match API**: Show member-centric view (select member → see their transactions)
2. **Load all member transactions in memory**: Fetch all members → fetch transactions for each → combine + paginate (inefficient)
3. **Implement new API endpoint** (requires backend changes): POST /transactions (to list all) - would require design + implementation in backend

**RECOMMENDATION**:
- For Phase 2, follow Option 1: Implement member-centric journal
- First show summary of all outstanding transactions from GET /dashboard
- Then let user click on member to see detailed journal
- Or: Add to-do for backend to implement GET /transactions list endpoint

### Actual Implementation (Phase 2)

**Use GET /members/{memberId}/transactions** for member journal view:
```
Request:
  memberId: UUID (path parameter)
  date_from: date (optional)
  date_to: date (optional)
  type: all | purchase | correction
  include_settled: boolean (default false)

Response: MemberTransactionHistory
  - member_id: UUID
  - current_balance_cents: int
  - transactions: [
      {
        id: UUID,
        date: ISO date-time,
        type: "purchase" | "correction",
        description: string,
        amount_cents: int,
        running_total_cents: int,
        settlement_id: UUID (null if not settled)
      },
      ...
    ]
  - settlements: [
      { id, date, amount_cents, type },
      ...
    ]
```

### Alternative: Dashboard-Based View

Show transaction summary from GET /dashboard:
```
Response.recent_transactions: [
  {
    id: UUID,
    timestamp: ISO date-time,
    member_name: string,
    product_name: string,
    amount_cents: int
  },
  ...
]
```

This provides recent transactions across all members (limited set).

### API → Prototype Mapping

| Prototype UI | API Data | Notes |
|---|---|---|
| **Journal View** | **MISMATCH**: Prototype shows all transactions, API only has per-member | See recommendation above |
| Filter tabs: "Alle" | type: all | All transactions for a member |
| Filter tabs: "Konsumationen" | type: purchase | Purchase transactions only |
| Filter tabs: "Korrekturen" | type: correction | Correction transactions only |
| Table: Datum | date | ISO date-time, format as DD.MM.YYYY HH:MM |
| Table: Mitglied | (member name from parent context) | Get from member object |
| Table: RFID | (card_uid from parent context) | Get from member object |
| Table: Artikel | description | Product name or description |
| Table: Anzahl | (not in API) | **API doesn't track quantity separately** |
| Table: Einzelpreis | (not in API) | **API doesn't track per-unit price** |
| Table: Gesamt | amount_cents | Total transaction amount |
| Pagination | transactions array + manual pagination | Need to paginate in-memory on client |

### Implementation Checklist

- [ ] Decide: Use member-centric or dashboard-based journal view
- [ ] If member-centric: Show member selector + transaction table
- [ ] If dashboard-based: Show recent transactions from GET /dashboard
- [ ] Filter by transaction type (all/purchase/correction)
- [ ] Format dates as DD.MM.YYYY HH:MM
- [ ] Format amounts as €X,XX
- [ ] Handle pagination (if many transactions, paginate client-side)
- [ ] Optional: Export transactions to CSV using GET /transactions/export
- [ ] Note to-do: Implement GET /transactions list endpoint in backend for full journal view

---

## Page 4: Settlements (Abrechnungen)

### Prototype UI Elements
```
[Settlement List]
├─ Count: "2 Abrechnungen"
├─ "Abrechnung erstellen" button
└─ Settlements displayed as cards:
   ├─ Datum: 31.12.2025
   ├─ Mitglieder: 4
   ├─ Gesamtbetrag: 245,60 €
   └─ Buttons: Details, CSV, Widerrufen (Revoke)
```

### API Endpoints

**1. GET /settlements** - List settlements (paginated)
```
Request:
  page: 1
  per_page: 25
  type: all | sepa | manual
  sort_by: created_at_desc | created_at_asc | execution_date

Response: SettlementListItem[]
  - id (UUID)
  - settlement_type: sepa | manual
  - settlement_date (date)
  - execution_date (date or null for manual)
  - member_count (int)
  - total_amount_cents (int)
  - is_cancelled (boolean)
  - exported_at (date-time or null)
  - created_at (date-time)
  - pagination: { page, per_page, total, total_pages }
```

**2. GET /settlements/preview** - Preview unsettled transactions
```
Request:
  date_from: date (optional)
  date_to: date (optional)
  member_id: UUID (optional)

Response: SettlementPreview
  - total_amount_cents (int)
  - member_count (int)
  - transaction_count (int)
  - sepa_eligible_count (int)
  - sepa_ineligible_count (int)
  - members: [
      {
        member_id: UUID,
        member_name: string,
        amount_cents: int,
        iban_masked: string (or null),
        mandate_reference: string (or null),
        is_sepa_eligible: boolean
      },
      ...
    ]
```

**3. POST /settlements** - Create settlement
```
Request:
  settlement_type: sepa | manual (required)

  # For SEPA:
  execution_date: date (required, must be >= TODAY + 7 days)

  # For Manual:
  manual_reason: cash_payment | bank_transfer | other_payment | write_off | goodwill | correction | other
  notes: string (min 10 chars)

Response: Settlement (full object)
  - id, settlement_type, settlement_date, execution_date
  - period_start, period_end (nullable)
  - sepa_message_id (nullable)
  - manual_reason (nullable)
  - total_amount_cents, member_count
  - is_cancelled, cancelled_at
  - exported_at, notes, created_by_admin_id, created_at
  - members: array of member details
```

**4. GET /settlements/{settlementId}** - Get settlement details
```
Response: Settlement (full object with member details)
```

**5. POST /settlements/{settlementId}/finalize** - Finalize settlement
```
Request: (none)

Response: Settlement (updated object)
```

**6. POST /settlements/{settlementId}/cancel** - Cancel settlement
```
Request:
  reason: string (optional)

Response: Settlement (with is_cancelled = true, cancelled_at = now)
```

**7. GET /settlements/{settlementId}/export/csv** - Export as CSV
```
Response: CSV file (download)
```

**8. GET /settlements/{settlementId}/export/sepa-xml** - Export SEPA XML
```
Response: XML file (pain.008.001.02 format)
  - Only available for SEPA settlements
```

### API → Prototype Mapping

| Prototype UI | API Data | Notes |
|---|---|---|
| Settlement count | pagination.total from GET /settlements | "2 Abrechnungen" |
| Settlement card: Datum | settlement_date | Format as DD.MM.YYYY |
| Settlement card: Mitglieder | member_count | Number of members in settlement |
| Settlement card: Gesamtbetrag | total_amount_cents | Format as €X,XX (color: green) |
| Button: Details | GET /settlements/{id} | Show modal with full details |
| Button: CSV | GET /settlements/{id}/export/csv | Download CSV file |
| Button: Widerrufen | POST /settlements/{id}/cancel | Soft delete + confirmation |
| "Abrechnung erstellen" | POST /settlements (show modal for type selection) | Choose SEPA or Manual |

### Create Settlement Workflow

**Modal 1: Type Selection**
- Option: SEPA Direct Debit
- Option: Manual Settlement

**Modal 2: SEPA Settlement Creation**
- Execution date (date picker, min = TODAY + 7 days)
- Preview (call GET /settlements/preview first)
- Confirm

**Modal 3: Manual Settlement Creation**
- Reason (dropdown): cash_payment, bank_transfer, other_payment, write_off, goodwill, correction, other
- Notes (textarea, min 10 chars)
- Preview (call GET /settlements/preview first)
- Confirm

### Implementation Checklist

- [ ] GET /settlements list with pagination and filtering
- [ ] Settlement list as card layout (not table)
- [ ] Create Settlement button → modal for type selection
- [ ] GET /settlements/preview to show preview before creating
- [ ] Create SEPA settlement modal (execution_date picker, min TODAY+7)
- [ ] Create Manual settlement modal (reason dropdown, notes textarea)
- [ ] POST /settlements to create
- [ ] Settlement Details modal (full member list, amounts, status)
- [ ] POST /settlements/{id}/cancel with confirmation
- [ ] GET /settlements/{id}/export/csv download link
- [ ] GET /settlements/{id}/export/sepa-xml download link (SEPA only)
- [ ] Format dates as DD.MM.YYYY
- [ ] Format amounts as €X,XX

---

## Page 5: Statistics (Statistik)

### Prototype UI Elements
```
[Month Navigation]
├─ "Vorheriger" button (previous month)
├─ "Januar 2026 · 24 Transaktionen"
└─ "Nächster" button (disabled if current/future month)

[Metric Cards]
├─ 274,40 € Gesamtumsatz (total revenue)
├─ 91 Verkaufte Artikel (items sold count)
└─ Pils 0,5l Top Produkt (top product)

[Charts]
├─ 📈 Tagesumsatz (daily revenue bar chart, days 1-31)
├─ 🏆 Top Produkte (ranked list with revenue)
└─ 📊 Umsatz nach Produkt (product breakdown with % bars)
```

### API Endpoints

**1. GET /dashboard** - Dashboard overview
```
Response: Dashboard
  - metrics: {
      active_members: int,
      outstanding_balance_cents: int,
      today_revenue_cents: int,
      terminal_status: online | offline | unknown,
      last_sync_at: ISO date-time (nullable)
    }
  - alerts: [
      { type: string, severity: info|warning|error, count: int, message: string },
      ...
    ]
  - recent_transactions: [
      { id, timestamp, member_name, product_name, amount_cents },
      ...
    ]
  - system_status: {
      pending_syncs: int,
      last_settlement_date: date (nullable)
    }
```

**2. GET /reports/{reportType}** - Detailed reports
```
Request:
  reportType: revenue | consumption | transactions
  date_from: date (optional)
  date_to: date (optional)
  group_by: category | product | member | day | week | month | year
  category_ids: string (comma-separated UUIDs, optional)
  product_ids: string (comma-separated UUIDs, optional)
  page: 1
  per_page: 25

Response: Report
  - metadata: { report_type, generated_at, filters }
  - summary: {
      total_revenue_cents: int,
      total_quantity: int,
      transaction_count: int,
      avg_transaction_cents: int
    }
  - data: [
      {
        dimension: string (product name, category, date, etc.),
        revenue_cents: int,
        quantity: int,
        count: int,
        percent_of_total: float (0-100)
      },
      ...
    ]
  - pagination: { page, per_page, total, total_pages }
```

**3. GET /reports/member-ranking** - Top spenders ranking
```
Response: (similar to Report but with member-specific data)
```

**4. GET /reports/terminal-activity** - Terminal usage report
```
Response: TerminalActivityReport
  - sessions: [
      { date, start_time, end_time, transaction_count, revenue_cents },
      ...
    ]
  - hourly_distribution: [
      { hour: 0-23, transaction_count },
      ...
    ]
  - terminals: [
      { id, name, transaction_count, last_sync_at },
      ...
    ]
```

### API → Prototype Mapping

| Prototype UI | API Data | Notes |
|---|---|---|
| Month navigation | Client-side month selection + GET /reports with date_from/date_to | Calculate month date range |
| "Januar 2026 · 24 Transaktionen" | report.summary.transaction_count | Show selected month + count |
| Metric: "274,40 € Gesamtumsatz" | report.summary.total_revenue_cents | Format as €X,XX |
| Metric: "91 Verkaufte Artikel" | report.summary.total_quantity | Sum of quantities sold |
| Metric: "Pils 0,5l Top Produkt" | report.data[0] (sorted by revenue desc) | Top product by revenue |
| Chart: Tagesumsatz (daily bars) | GET /reports?group_by=day | Data point per day (1-31) |
| Chart: Top Produkte (ranking) | GET /reports?group_by=product (top 6) | Ranked list with revenue |
| Chart: Umsatz nach Produkt (% bars) | report.data | Use percent_of_total field |

### Implementation Notes

✅ **Statistics Page Uses `/reports/{reportType}` Endpoint**
- Request date range as query params (date_from, date_to)
- Request group_by=day for daily breakdown
- Request group_by=product for product ranking

✅ **Month Navigation**
- Select month (prev/next buttons)
- Calculate date_from = first day of month, date_to = last day of month
- Call GET /reports/revenue?date_from=2026-01-01&date_to=2026-01-31

✅ **Charts Can Use Recharts or Chart.js Library**
- Bar chart for daily revenue (X-axis: days 1-31, Y-axis: revenue €)
- Ranked list for top products
- Horizontal bar chart for product revenue breakdown

### Implementation Checklist

- [ ] Calendar/month selector (prev/next buttons)
- [ ] GET /reports/revenue with date_from/date_to for selected month
- [ ] Display summary metrics (total revenue, items sold, top product)
- [ ] Bar chart for daily revenue (group_by=day)
- [ ] Ranked table for top products (group_by=product, limit 6)
- [ ] Horizontal bar chart for product revenue % breakdown
- [ ] Format amounts as €X,XX with German locale
- [ ] Format percentages with decimals (X.X%)
- [ ] Handle edge cases (no transactions in month, etc.)
- [ ] Consider using Recharts library for charts

---

## Summary: API Completeness vs Prototype

### ✅ Fully Supported by API
- **Members**: Complete CRUD + transaction history
- **Products**: Complete CRUD + categories
- **Settlements**: Complete workflow (preview, create, finalize, cancel, export)
- **Dashboard**: Metrics + recent transactions

### ⚠️ Partial Support (API has more, prototype shows less)
- **Members**: API has status/balance/sepa_status filters, prototype doesn't show these
- **Products**: API has multilingual support, prototype shows single language
- **Settlements**: API supports SEPA + Manual types, prototype doesn't distinguish

### ❌ Not Supported by API (would need backend enhancement)
- **Journal**: Prototype shows global transaction list, API only has per-member view
  - **Solution**: Change UI to member-centric or add GET /transactions endpoint to backend

### 🚧 Missing from Prototype (API supports, should implement)
- SEPA execution_date validation (min TODAY + 7 days)
- Manual settlement reason/notes requirements
- Advanced filtering/sorting options
- Category reordering (POST /categories/reorder)

---

## Implementation Priority (Phase 2)

1. **Members Page** ✅ (Complete API support)
2. **Products Page** ✅ (Complete API support)
3. **Statistics Page** ✅ (Complete API support)
4. **Settlements Page** ✅ (Complete API support)
5. **Journal Page** ⚠️ (Member-centric workaround; note to-do for backend enhancement)

**Recommended Phase 2 Scope**:
- Implement all 5 pages
- Flag journal as "member-centric view" (not global journal)
- Create to-do for backend: Add GET /transactions list endpoint
- Document multilingual product handling
- Test with German locale (de-DE) for formatting

---

## Phase 2 Scope vs Deferred Use Cases

**See [USE_CASE_AUDIT.md](./USE_CASE_AUDIT.md) for complete mapping of all 43 use cases to backend APIs.**

### ✅ Phase 2 Use Cases (In Scope - 25 UC)

These use cases are implemented by the 5 core pages:

**Members Page**:
- ✅ UC-A10: List Members
- ✅ UC-A11: Create Member
- ✅ UC-A12: Edit Member
- ✅ UC-A15: Deactivate Member

**Products Page**:
- ✅ UC-A40: List Products
- ✅ UC-A41: Create Product
- ✅ UC-A42: Edit Product
- ✅ UC-A43: Deactivate Product
- ✅ UC-A44: Manage Categories

**Journal Page** (Member-centric):
- ✅ UC-A20: View Tab (member's transaction history + balance)

**Settlements Page**:
- ✅ UC-A30: Create Settlement (SEPA + Manual)
- ✅ UC-A31: Download SEPA XML
- ✅ UC-A32: Download CSV
- ✅ UC-A33: Settlement History
- ✅ UC-A34: Settlement Details
- ✅ UC-A35: Manual Settlement

**Statistics Page**:
- ✅ UC-A50: Reports (Revenue, Consumption, Transactions)
- ✅ UC-A51: Member Ranking
- ✅ UC-A52: Terminal Activity
- ✅ UC-A80: Dashboard Overview

**Authentication** (Phase 1, carried forward):
- ✅ UC-A01: Login
- ✅ UC-A02: Logout
- ✅ UC-A03: Change Password

**Total Phase 2**: 25 use cases ✅

---

### 🔄 Phase 3+ Use Cases (Deferred - 18 UC)

#### Phase 3A: Settings & Compliance (5 pages, 8 UC)

- 🔄 UC-A60: Edit Organization (SEPA Config page)
- 🔄 UC-A61: Manage Admin Users (Admin Users list page)
- 🔄 UC-A62: Create Admin User (Create admin modal)
- 🔄 UC-A63: Reset Admin Password (Reset action)
- 🔄 UC-A81: Audit Log (Audit log page)
- 🔄 UC-A82: SEPA Validation Report (SEPA Issues page)

#### Phase 3B: RFID Card Management (1 page, 4 UC)

- 🔄 UC-A13: Assign RFID Card (from member or cards page)
- 🔄 UC-A14: Remove RFID Card (from member detail)
- 🔄 UC-A70: Unassigned Cards (Cards management page)
- 🔄 UC-A71: Block Card (Cards page action)

#### Phase 4: Member Import (1 page, 1 UC)

- 🔄 UC-A16: Import Members (CSV import page)

#### Phase 5+: Transaction Corrections (3 UC)

- ⏸️ UC-A21: Manual Booking (transaction corrections)
- ⏸️ UC-A22: Export Transactions (standalone export - low priority)

#### TBD: Terminal Management (7 UC - Backend Complete)

- ⏸️ UC-A50-A55: Terminal CRUD + Token Rotation (backend module complete, UI TBD)

**Total Deferred**: 18+ use cases

---

### Rationale for Phase 2 Scope

**Why these 5 pages for Phase 2?**

1. **Core POS Workflow**: Members, Products, Settlements, Statistics are the primary admin tasks
2. **API Completeness**: All required backend APIs implemented and tested
3. **Prototype Alignment**: Prototype focuses on these 5 pages; other pages require new design
4. **High Value**: 60% of use case volume in single implementation sprint
5. **Foundation Building**: Establishes patterns for form handling, tables, filtering that apply to later phases

**Why defer RFID, Settings, Import to Phase 3+?**

1. **Secondary Workflows**: Not part of daily transaction processing
2. **Complex Interactions**: RFID needs unknown cards sync; Settings need multi-section forms; Import needs preview UX
3. **Lower Frequency**: Used by admins occasionally, not transaction-on-transaction
4. **Reduces Phase 2 Risk**: Focus on core 5 pages ensures delivery on timeline
5. **Better for Team**: Settings/RFID can be handled by different team members once Phase 2 patterns established

**When to start Phase 3?**

- After Phase 2 Pages 1-3 (Members, Products, Statistics) are production-ready
- This provides 3-4 weeks head start for Settings design while Phase 2 is polished
- RFID phase can follow immediately after Cards page design is ready

---

## Backend API Status: 100% Ready ✅

All backend APIs required for Phase 2, Phase 3, and Phase 4 are **already implemented** and tested with E2E tests.

No backend work needed before starting Phase 2 implementation.

See [USE_CASE_AUDIT.md](./USE_CASE_AUDIT.md) for complete API endpoint inventory.

